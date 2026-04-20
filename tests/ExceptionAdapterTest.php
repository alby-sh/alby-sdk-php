<?php

declare(strict_types=1);

use Alby\Report\ExceptionAdapter;

it('normalises a Throwable into the wire shape', function (): void {
    $adapter = new ExceptionAdapter();
    $e       = new RuntimeException('boom');
    $out     = $adapter->fromThrowable($e);

    expect($out)->toHaveKeys(['type', 'value', 'frames']);
    expect($out['type'])->toBe('RuntimeException');
    expect($out['value'])->toBe('boom');
    expect($out['frames'])->toBeArray()->not->toBeEmpty();

    // The innermost frame is the throw site — its filename is this file.
    $first = $out['frames'][0];
    expect($first['filename'])->toBe(__FILE__);
    expect($first['lineno'])->toBeGreaterThan(0);
});

it('walks the previous-exception chain', function (): void {
    $adapter = new ExceptionAdapter();

    $root = new LogicException('root cause');
    $mid  = new RuntimeException('middle', 0, $root);
    $top  = new DomainException('top', 0, $mid);

    $out = $adapter->fromThrowable($top);
    expect($out['type'])->toBe('DomainException');
    expect($out['value'])->toBe('top');
    // Chain adds frames from mid + root — so strictly more frames than a lone top.
    $loneTop = $adapter->fromThrowable(new DomainException('solo'));
    expect(count($out['frames']))->toBeGreaterThan(count($loneTop['frames']));
});

it('collects source context lines', function (): void {
    $adapter = new ExceptionAdapter();
    // Throw on a known line to make assertions stable.
    $thrower = function (): void {
        throw new RuntimeException('ctx');
    };
    try {
        $thrower();
    } catch (RuntimeException $e) {
        $out = $adapter->fromThrowable($e);
    }

    expect($out['frames'])->not->toBeEmpty();
    $firstWithFile = null;
    foreach ($out['frames'] as $f) {
        if (($f['filename'] ?? null) === __FILE__) {
            $firstWithFile = $f;
            break;
        }
    }
    expect($firstWithFile)->not->toBeNull();
    expect($firstWithFile['context_line'] ?? '')->toContain('throw new RuntimeException');
    expect($firstWithFile['pre_context'] ?? [])->toBeArray();
    expect($firstWithFile['post_context'] ?? [])->toBeArray();
});

it('handles throws from vanished files gracefully', function (): void {
    // Synthesize a fake trace with a non-existent file path — exercises
    // the "file not readable → no context" branch.
    $adapter = new ExceptionAdapter();
    $ref     = new ReflectionClass($adapter);
    $method  = $ref->getMethod('readContext');
    $method->setAccessible(true);

    $out = $method->invoke($adapter, '/tmp/does-not-exist-xyz-abc.php', 1);
    expect($out)->toBe([null, null, null]);
});
