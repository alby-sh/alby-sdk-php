<?php

declare(strict_types=1);

namespace Alby\Report;

/**
 * Bounded ring buffer of breadcrumbs. Oldest → newest ordering.
 *
 * The wire protocol says "cap at 100 entries client-side"; when a new
 * breadcrumb would exceed the cap, we drop the oldest one.
 */
final class Breadcrumbs
{
    public const MAX = 100;

    /** @var list<array<string, mixed>> */
    private array $items = [];

    private readonly int $max;

    public function __construct(int $max = self::MAX)
    {
        $this->max = max(1, $max);
    }

    /**
     * @param array<string, mixed> $crumb
     */
    public function add(array $crumb): void
    {
        if (!isset($crumb['timestamp']) || !is_string($crumb['timestamp'])) {
            $crumb['timestamp'] = self::nowIso();
        }
        $this->items[] = $crumb;

        // Trim oldest while over-cap.
        if (count($this->items) > $this->max) {
            $this->items = array_slice($this->items, -$this->max);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return $this->items;
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function clear(): void
    {
        $this->items = [];
    }

    private static function nowIso(): string
    {
        return gmdate('Y-m-d\TH:i:s') . '.' . substr((string) ((int) (microtime(true) * 1000) % 1000), 0, 3) . 'Z';
    }
}
