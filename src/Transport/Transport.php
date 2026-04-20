<?php

declare(strict_types=1);

namespace Alby\Report\Transport;

/**
 * Transport contract: buffer events locally and flush them on demand.
 *
 * Implementations must be non-blocking at `send()` time — buffer the event in
 * memory and only perform I/O when `flush()` is called (either explicitly by
 * the caller, or by the SDK's registered shutdown function).
 */
interface Transport
{
    /**
     * Queue an event for later delivery.
     *
     * @param array<string, mixed> $payload Protocol-shaped event
     */
    public function send(array $payload, string $publicKey, string $ingestUrl): void;

    /**
     * Deliver all buffered events synchronously.
     *
     * @return bool True if every buffered event was accepted (2xx) within the timeout.
     */
    public function flush(int $timeoutMs = 2000): bool;

    /**
     * How many events are currently buffered.
     */
    public function pending(): int;
}
