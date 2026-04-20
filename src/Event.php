<?php

declare(strict_types=1);

namespace Alby\Report;

/**
 * Helpers for building a protocol-compliant event payload and for generating
 * UUIDv4s for `event_id`.
 *
 * The Event "object" is just an associative array all the way through — we
 * have no reason to introduce a value object here; it'd only force an extra
 * toArray() call on every send.
 */
final class Event
{
    public const LEVELS = ['debug', 'info', 'warning', 'error', 'fatal'];

    /**
     * RFC 4122 v4 UUID. Uses `random_bytes` for a cryptographically-strong source.
     */
    public static function uuid4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        $hex = bin2hex($b);
        return substr($hex, 0, 8) . '-'
            . substr($hex, 8, 4) . '-'
            . substr($hex, 12, 4) . '-'
            . substr($hex, 16, 4) . '-'
            . substr($hex, 20, 12);
    }

    /**
     * ISO-8601 with millisecond precision, UTC ("Z"). Matches the wire examples.
     */
    public static function nowIso(): string
    {
        $t  = microtime(true);
        $s  = (int) floor($t);
        $ms = (int) round(($t - $s) * 1000);
        if ($ms === 1000) { // edge rounding: carry
            $s  += 1;
            $ms  = 0;
        }
        return gmdate('Y-m-d\TH:i:s', $s) . '.' . str_pad((string) $ms, 3, '0', STR_PAD_LEFT) . 'Z';
    }

    /**
     * Drop null / empty-array values from a payload so we emit a tidy JSON.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function prune(array $payload): array
    {
        foreach ($payload as $k => $v) {
            if ($v === null) {
                unset($payload[$k]);
                continue;
            }
            if (is_array($v) && $v === []) {
                unset($payload[$k]);
            }
        }
        return $payload;
    }
}
