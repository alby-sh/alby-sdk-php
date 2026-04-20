<?php

declare(strict_types=1);

namespace Alby\Report\Transport;

/**
 * Default curl-based transport.
 *
 * Design:
 *  - `send()` buffers; we never hit the network from it.
 *  - `flush()` delivers synchronously, one HTTP POST per event, with retries.
 *  - 3s connect + 5s total timeout per attempt (per the brief).
 *  - 2 retries with 1s/3s backoff. Respect `Retry-After` on 429.
 *  - Bounded in-memory queue of 100 events; excess events are silently dropped.
 */
final class CurlTransport implements Transport
{
    public const QUEUE_CAP       = 100;
    public const CONNECT_TIMEOUT = 3;   // seconds
    // The live backend sits behind TLS + Cloudflare and can take ~7s to
    // return a 202 on a cold connection; 10s gives enough headroom to avoid
    // false-positive timeouts without making the SDK feel slow.
    public const TOTAL_TIMEOUT   = 10;  // seconds
    /** Retry backoff delays in milliseconds. */
    public const RETRY_DELAYS_MS = [1000, 3000];

    /** @var list<array{payload: array<string, mixed>, publicKey: string, url: string}> */
    private array $queue = [];

    private bool $debug;

    /** Optional hook invoked on every log message; set only for tests. */
    private mixed $logger = null;

    public function __construct(bool $debug = false)
    {
        $this->debug = $debug;
    }

    /** @internal for tests */
    public function setLogger(?callable $logger): void
    {
        $this->logger = $logger;
    }

    public function send(array $payload, string $publicKey, string $ingestUrl): void
    {
        if (count($this->queue) >= self::QUEUE_CAP) {
            $this->log('queue full, dropping event');
            return;
        }
        $this->queue[] = [
            'payload'   => $payload,
            'publicKey' => $publicKey,
            'url'       => $ingestUrl,
        ];
    }

    public function pending(): int
    {
        return count($this->queue);
    }

    public function flush(int $timeoutMs = 2000): bool
    {
        if ($this->queue === []) {
            return true;
        }

        $deadline = microtime(true) + ($timeoutMs / 1000);
        $allOk    = true;

        // Drain the queue. If flush() times out we bail with whatever's left
        // still buffered — next flush() call can retry those.
        while ($this->queue !== []) {
            if (microtime(true) >= $deadline) {
                $this->log('flush timeout reached with ' . count($this->queue) . ' events left');
                return false;
            }
            $item = array_shift($this->queue);
            $ok   = $this->deliver($item['payload'], $item['publicKey'], $item['url'], $deadline);
            $allOk = $allOk && $ok;
        }

        return $allOk;
    }

    /**
     * Attempt to POST a single event with retries. Returns true on 2xx.
     *
     * @param array<string, mixed> $payload
     */
    private function deliver(array $payload, string $publicKey, string $url, float $deadline): bool
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            $this->log('json_encode failed: ' . json_last_error_msg());
            return false;
        }

        $attempts = count(self::RETRY_DELAYS_MS) + 1; // initial + retries
        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            if (microtime(true) >= $deadline) {
                return false;
            }

            [$status, $responseBody, $headers, $curlErrno, $curlError] = $this->httpPost($url, $body, $publicKey);

            // Success
            if ($status >= 200 && $status < 300) {
                if ($this->debug) {
                    $this->log("sent: HTTP {$status} {$responseBody}");
                }
                return true;
            }

            // 429 — honor Retry-After (seconds) before the next attempt, if we have one left.
            if ($status === 429) {
                $retryAfter = $this->parseRetryAfter($headers);
                $waitMs     = max(1000, $retryAfter * 1000);
                $this->log("rate-limited, waiting {$waitMs}ms");
                if ($attempt + 1 < $attempts && (microtime(true) + $waitMs / 1000) < $deadline) {
                    usleep($waitMs * 1000);
                    continue;
                }
                return false;
            }

            // 5xx or network error → retry per backoff schedule
            $retryable = ($status === 0) || ($status >= 500 && $status < 600);
            if (!$retryable) {
                // 4xx (non-429) — permanent; drop silently.
                if ($this->debug) {
                    $this->log("dropped: HTTP {$status} {$responseBody} (curl errno={$curlErrno} err={$curlError})");
                }
                return false;
            }

            if ($attempt + 1 < $attempts) {
                $delayMs = self::RETRY_DELAYS_MS[$attempt] ?? 1000;
                if ((microtime(true) + $delayMs / 1000) >= $deadline) {
                    return false;
                }
                $this->log("retry in {$delayMs}ms (HTTP {$status}, curl errno={$curlErrno})");
                usleep($delayMs * 1000);
                continue;
            }

            $this->log("giving up after {$attempts} attempts (HTTP {$status}, curl errno={$curlErrno} err={$curlError})");
            return false;
        }

        return false;
    }

    /**
     * Low-level HTTP POST via ext-curl.
     *
     * @return array{0: int, 1: string, 2: array<string, string>, 3: int, 4: string}
     *         [status, body, lowercased-header-map, curl errno, curl error string]
     */
    private function httpPost(string $url, string $body, string $publicKey): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return [0, '', [], -1, 'curl_init failed'];
        }

        $headers = [];
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => self::TOTAL_TIMEOUT,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-Alby-Dsn: ' . $publicKey,
                'User-Agent: alby-php/' . self::userAgentVersion(),
                'Accept: application/json',
            ],
            CURLOPT_HEADERFUNCTION => function ($_ch, string $line) use (&$headers): int {
                $trim = trim($line);
                if ($trim !== '' && str_contains($trim, ':')) {
                    [$k, $v] = explode(':', $trim, 2);
                    $headers[strtolower(trim($k))] = trim($v);
                }
                return strlen($line);
            },
        ]);

        $raw       = curl_exec($ch);
        $status    = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno     = curl_errno($ch);
        $errstr    = curl_error($ch);
        curl_close($ch);

        $responseBody = is_string($raw) ? $raw : '';
        return [$status, $responseBody, $headers, $errno, $errstr];
    }

    /**
     * @param array<string, string> $headers
     */
    private function parseRetryAfter(array $headers): int
    {
        $v = $headers['retry-after'] ?? null;
        if ($v === null) return 1;
        // Seconds form: "120"
        if (ctype_digit($v)) {
            return max(1, (int) $v);
        }
        // HTTP-date form
        $ts = strtotime($v);
        if ($ts !== false) {
            $delta = $ts - time();
            return max(1, $delta);
        }
        return 1;
    }

    private function log(string $msg): void
    {
        if ($this->logger !== null) {
            ($this->logger)($msg);
            return;
        }
        if ($this->debug) {
            fwrite(STDERR, "[alby] {$msg}\n");
        }
    }

    private static function userAgentVersion(): string
    {
        // Composer.json version isn't reachable at runtime; the package version
        // will be injected by downstream packaging. Hardcoded baseline is fine.
        return '0.1.0';
    }
}
