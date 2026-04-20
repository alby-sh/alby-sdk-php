<?php

declare(strict_types=1);

namespace Alby\Report\Tests\Support;

use Alby\Report\Transport\Transport;

/**
 * Non-network transport for tests. Captures every event in memory and reports
 * success without touching HTTP. Flushes eagerly.
 */
final class FakeTransport implements Transport
{
    /** @var list<array{payload: array<string, mixed>, publicKey: string, url: string}> */
    public array $sent = [];

    public bool $flushResult = true;

    public function send(array $payload, string $publicKey, string $ingestUrl): void
    {
        $this->sent[] = [
            'payload'   => $payload,
            'publicKey' => $publicKey,
            'url'       => $ingestUrl,
        ];
    }

    public function flush(int $timeoutMs = 2000): bool
    {
        return $this->flushResult;
    }

    public function pending(): int
    {
        return 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function last(): ?array
    {
        return $this->sent === [] ? null : $this->sent[array_key_last($this->sent)]['payload'];
    }
}
