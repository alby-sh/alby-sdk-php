<?php

declare(strict_types=1);

use Alby\Report\Breadcrumbs;

it('preserves insertion order', function (): void {
    $b = new Breadcrumbs();
    $b->add(['message' => 'a']);
    $b->add(['message' => 'b']);
    $b->add(['message' => 'c']);
    $all = $b->all();
    expect(array_column($all, 'message'))->toBe(['a', 'b', 'c']);
});

it('enforces the ring-buffer cap', function (): void {
    $b = new Breadcrumbs(3);
    $b->add(['message' => 'one']);
    $b->add(['message' => 'two']);
    $b->add(['message' => 'three']);
    $b->add(['message' => 'four']);
    expect(array_column($b->all(), 'message'))->toBe(['two', 'three', 'four']);
    expect($b->count())->toBe(3);
});

it('auto-populates a timestamp when not supplied', function (): void {
    $b = new Breadcrumbs();
    $b->add(['message' => 'x']);
    $ts = $b->all()[0]['timestamp'] ?? null;
    expect($ts)->toBeString();
    expect($ts)->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/');
});

it('respects user-supplied timestamps', function (): void {
    $b = new Breadcrumbs();
    $b->add(['message' => 'x', 'timestamp' => '2026-01-01T00:00:00.000Z']);
    expect($b->all()[0]['timestamp'])->toBe('2026-01-01T00:00:00.000Z');
});
