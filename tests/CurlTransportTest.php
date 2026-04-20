<?php

declare(strict_types=1);

use Alby\Report\Transport\CurlTransport;

/**
 * Spin up php -S on a free port, POST a real event, verify the server received
 * a protocol-shaped payload with the expected auth header.
 */
function startTestServer(string $logPath): array
{
    // Find a free port by binding, then releasing.
    $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if (!$sock) throw new RuntimeException("can't bind: {$errstr}");
    $port = (int) explode(':', stream_socket_get_name($sock, false))[1];
    fclose($sock);

    $fixture = __DIR__ . '/fixtures/ingest-server.php';
    $cmd = sprintf(
        'ALBY_TEST_LOG=%s %s -S 127.0.0.1:%d %s',
        escapeshellarg($logPath),
        escapeshellarg(PHP_BINARY),
        $port,
        escapeshellarg($fixture),
    );

    $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $desc, $pipes);
    if (!is_resource($proc)) throw new RuntimeException('failed to launch php -S');

    // Wait for the server to become reachable.
    $deadline = microtime(true) + 5.0;
    while (microtime(true) < $deadline) {
        $errno = 0; $errstr = '';
        set_error_handler(static fn () => true); // swallow "connection refused" warnings
        $fp = fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        restore_error_handler();
        if ($fp) { fclose($fp); return [$proc, $port, $pipes]; }
        usleep(50_000);
    }
    proc_terminate($proc);
    throw new RuntimeException("php -S never became ready on port {$port}");
}

function stopTestServer(array $handle): void
{
    [$proc, , $pipes] = $handle;
    foreach ($pipes as $p) { if (is_resource($p)) fclose($p); }
    proc_terminate($proc);
    proc_close($proc);
}

it('POSTs a JSON event with the X-Alby-Dsn header and gets 202', function (): void {
    $log    = tempnam(sys_get_temp_dir(), 'alby-log-');
    $handle = startTestServer($log);

    try {
        $t   = new CurlTransport(debug: true);
        $t->setLogger(static function (string $msg): void { /* swallow in test */ });
        $url = 'http://127.0.0.1:' . $handle[1] . '/api/ingest/v1/events';
        $key = 'abcdef0123456789abcdef0123456789';

        $t->send([
            'event_id'  => '11111111-1111-4111-8111-111111111111',
            'platform'  => 'php',
            'level'     => 'error',
            'message'   => 'from curl test',
            'exception' => ['type' => 'TestException', 'value' => 'x', 'frames' => []],
        ], $key, $url);

        expect($t->pending())->toBe(1);
        $ok = $t->flush(5000);
        expect($ok)->toBeTrue();
        expect($t->pending())->toBe(0);
    } finally {
        stopTestServer($handle);
    }

    $lines = array_values(array_filter(array_map('trim', explode("\n", (string) file_get_contents($log)))));
    expect($lines)->toHaveCount(1);
    $rec = json_decode($lines[0], true);
    @unlink($log);

    expect($rec['path'])->toBe('/api/ingest/v1/events');
    expect($rec['headers']['x-alby-dsn'] ?? null)->toBe($key);
    expect($rec['headers']['content-type'] ?? null)->toBe('application/json');
    $body = json_decode($rec['body'], true);
    expect($body['platform'])->toBe('php');
    expect($body['message'])->toBe('from curl test');
    expect($body['exception']['type'])->toBe('TestException');
});
