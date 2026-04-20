<?php

declare(strict_types=1);

/**
 * End-to-end smoke test against the live Alby backend.
 *
 * Usage:
 *   php examples/smoke.php
 *
 * Exits 0 on success (all events accepted with 2xx), 1 otherwise.
 */

require __DIR__ . '/../vendor/autoload.php';

use Alby\Report\Alby;

$dsn = getenv('ALBY_DSN') ?: 'https://5e21bf08520734b6734b95f80af40cba6a7efc6cebddd0df@alby.sh/ingest/v1/a195c5dc-01c3-46b3-9db4-b22334c179c9';

Alby::init([
    'dsn'           => $dsn,
    'release'       => 'sdk-php-e2e',
    'environment'   => 'test',
    'auto_register' => false,
    'debug'         => true,
]);

Alby::addBreadcrumb([
    'type'     => 'test',
    'category' => 'smoke',
    'message'  => 'about to capture exception',
]);

Alby::setTag('sdk', 'php');
Alby::setUser(['id' => 'smoke-test-user', 'email' => 'smoke@alby.sh']);

Alby::captureException(new RuntimeException('SDK e2e: captureException works'));
Alby::captureMessage('SDK e2e: captureMessage works', 'warning');

$ok = Alby::flush(30_000);

if ($ok) {
    fwrite(STDOUT, "[smoke] OK — all events flushed.\n");
    exit(0);
}

fwrite(STDERR, "[smoke] FAIL — flush returned false\n");
exit(1);
