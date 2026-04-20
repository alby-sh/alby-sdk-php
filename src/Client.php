<?php

declare(strict_types=1);

namespace Alby\Report;

use Alby\Report\Transport\CurlTransport;
use Alby\Report\Transport\Transport;
use Throwable;

/**
 * Main SDK client. Holds configuration + scope (user/tags/contexts/breadcrumbs)
 * and hands events to a Transport.
 *
 * Most users talk to this through the {@see Alby} static facade instead of
 * constructing a Client directly; multi-tenant hosts can instantiate multiple
 * Clients and route events manually.
 */
final class Client
{
    public const PLATFORM = 'php';

    private readonly Dsn $dsn;
    private readonly Transport $transport;
    private readonly ExceptionAdapter $adapter;
    private readonly Breadcrumbs $breadcrumbs;

    private readonly string $release;
    private readonly string $environment;
    private readonly float $sampleRate;
    private readonly string $serverName;
    private readonly bool $debug;

    /** @var array<string, mixed>|null */
    private ?array $user = null;

    /** @var array<string, string> */
    private array $tags = [];

    /** @var array<string, array<string, mixed>> */
    private array $contexts = [];

    /** @var list<callable(array<string, mixed>): (array<string, mixed>|null)> */
    private array $beforeSend = [];

    /**
     * @param array{
     *     dsn?: string,
     *     release?: string,
     *     environment?: string,
     *     sample_rate?: float,
     *     server_name?: string,
     *     debug?: bool,
     *     transport?: Transport,
     *     breadcrumbs_max?: int,
     *     auto_register?: bool
     * } $options
     */
    public function __construct(array $options)
    {
        if (!isset($options['dsn']) || !is_string($options['dsn']) || $options['dsn'] === '') {
            throw new \InvalidArgumentException('[alby] init: dsn is required');
        }

        $this->dsn         = Dsn::parse($options['dsn']);
        $this->release     = isset($options['release']) ? (string) $options['release'] : '';
        $this->environment = isset($options['environment']) ? (string) $options['environment'] : self::detectEnvironment();
        $this->sampleRate  = self::clamp01($options['sample_rate'] ?? 1.0);
        $this->serverName  = isset($options['server_name']) ? (string) $options['server_name'] : self::detectHostname();
        $this->debug       = (bool) ($options['debug'] ?? false);
        $this->transport   = $options['transport'] ?? new CurlTransport($this->debug);
        $this->adapter     = new ExceptionAdapter();
        $this->breadcrumbs = new Breadcrumbs($options['breadcrumbs_max'] ?? Breadcrumbs::MAX);
    }

    public function getDsn(): Dsn
    {
        return $this->dsn;
    }

    public function getTransport(): Transport
    {
        return $this->transport;
    }

    public function isDebug(): bool
    {
        return $this->debug;
    }

    /**
     * @param array<string, mixed> $overrides
     * @return string|null event_id, or null if the event was sampled out
     */
    public function captureException(Throwable $e, array $overrides = []): ?string
    {
        $exception = $this->adapter->fromThrowable($e);
        $partial   = $overrides;
        $partial['exception'] = $exception;
        $partial['level'] ??= 'error';
        return $this->dispatch($partial);
    }

    public function captureMessage(string $message, string $level = 'info'): ?string
    {
        return $this->dispatch([
            'message' => $message,
            'level'   => self::normalizeLevel($level),
        ]);
    }

    /**
     * @param array<string, mixed>|null $user keys: id, email, name, ip_address, plus any custom
     */
    public function setUser(?array $user): void
    {
        $this->user = $user;
    }

    public function setTag(string $key, string $value): void
    {
        if ($key === '') return;
        $this->tags[$key] = $value;
    }

    /**
     * @param array<string, mixed>|null $ctx null clears the key
     */
    public function setContext(string $key, ?array $ctx): void
    {
        if ($key === '') return;
        if ($ctx === null) {
            unset($this->contexts[$key]);
        } else {
            $this->contexts[$key] = $ctx;
        }
    }

    /**
     * @param array{type?: string, category?: string, message?: string, data?: array<string, mixed>, timestamp?: string} $crumb
     */
    public function addBreadcrumb(array $crumb): void
    {
        $this->breadcrumbs->add($crumb);
    }

    public function flush(int $timeoutMs = 2000): bool
    {
        return $this->transport->flush($timeoutMs);
    }

    /**
     * Register a beforeSend hook. Return null from the hook to drop the event.
     *
     * @param callable(array<string, mixed>): (array<string, mixed>|null) $fn
     */
    public function onBeforeSend(callable $fn): void
    {
        $this->beforeSend[] = $fn;
    }

    /**
     * Build the full wire payload and hand it to the transport.
     *
     * @param array<string, mixed> $partial
     */
    private function dispatch(array $partial): ?string
    {
        // Sampling: uniform random in [0, 1).
        if ($this->sampleRate < 1.0 && (mt_rand() / mt_getrandmax()) >= $this->sampleRate) {
            return null;
        }

        $eventId = Event::uuid4();

        $payload = [
            'event_id'    => $eventId,
            'timestamp'   => Event::nowIso(),
            'platform'    => self::PLATFORM,
            'level'       => self::normalizeLevel($partial['level'] ?? 'error'),
            'release'     => $this->release !== '' ? $this->release : null,
            'environment' => $this->environment !== '' ? $this->environment : null,
            'server_name' => $this->serverName !== '' ? $this->serverName : null,
            'message'     => isset($partial['message']) ? (string) $partial['message'] : null,
            'exception'   => $partial['exception'] ?? null,
            'breadcrumbs' => $this->breadcrumbs->count() > 0 ? $this->breadcrumbs->all() : null,
            'contexts'    => $this->buildContexts(),
            'tags'        => $this->tags !== [] ? $this->tags : null,
            'extra'       => $partial['extra'] ?? null,
        ];

        // Overrides from caller may carry release/environment/server_name/tags.
        foreach (['release', 'environment', 'server_name', 'tags', 'extra'] as $k) {
            if (array_key_exists($k, $partial) && $partial[$k] !== null) {
                $payload[$k] = $partial[$k];
            }
        }

        $payload = Event::prune($payload);

        // beforeSend hooks (last one wins for drop decision).
        foreach ($this->beforeSend as $hook) {
            $result = $hook($payload);
            if ($result === null) {
                return null;
            }
            $payload = $result;
        }

        $this->transport->send($payload, $this->dsn->publicKey, $this->dsn->ingestUrl);
        return $eventId;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildContexts(): ?array
    {
        $out = $this->contexts;
        if ($this->user !== null) {
            $out['user'] = $this->user;
        }
        $out['runtime'] = ['name' => 'php', 'version' => PHP_VERSION];

        return $out === [] ? null : $out;
    }

    private static function normalizeLevel(string $level): string
    {
        $lvl = strtolower(trim($level));
        return in_array($lvl, Event::LEVELS, true) ? $lvl : 'error';
    }

    private static function clamp01(float|int $n): float
    {
        $f = (float) $n;
        if (!is_finite($f)) return 1.0;
        return max(0.0, min(1.0, $f));
    }

    private static function detectEnvironment(): string
    {
        $env = getenv('ALBY_ENV');
        if (is_string($env) && $env !== '') return $env;
        $env = getenv('APP_ENV');
        if (is_string($env) && $env !== '') return $env;
        return 'production';
    }

    private static function detectHostname(): string
    {
        $h = gethostname();
        return is_string($h) ? $h : '';
    }
}
