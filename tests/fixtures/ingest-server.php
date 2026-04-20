<?php

/**
 * Tiny ingest-server stub for the CurlTransport test.
 *
 * Every POST to /api/ingest/v1/events is appended to a JSONL log file whose
 * path is passed through the ALBY_TEST_LOG env var. The response mimics the
 * real Alby backend's 202 shape.
 *
 * The PHP built-in server spawns one worker per request, so env vars are
 * inherited but file-scoped state is not; the log file is the test's source
 * of truth.
 */

$path = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($path, PHP_URL_PATH) ?? '/';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $path !== '/api/ingest/v1/events') {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'not_found']);
    return;
}

$body       = file_get_contents('php://input') ?: '';
$headers    = getallheaders() ?: [];
$lowerHdr   = [];
foreach ($headers as $k => $v) $lowerHdr[strtolower((string) $k)] = (string) $v;

$logFile = getenv('ALBY_TEST_LOG');
if (!is_string($logFile) || $logFile === '') {
    http_response_code(500);
    echo json_encode(['error' => 'server_misconfigured']);
    return;
}

$record = [
    'path'    => $path,
    'headers' => $lowerHdr,
    'body'    => $body,
];
file_put_contents($logFile, json_encode($record) . "\n", FILE_APPEND | LOCK_EX);

// Optional: simulate specific responses via query string.
$q = $_GET ?? [];
if (isset($q['status'])) {
    $code = (int) $q['status'];
    http_response_code($code);
    if ($code === 429) {
        header('Retry-After: 1');
    }
    echo json_encode(['error' => 'simulated']);
    return;
}

http_response_code(202);
header('Content-Type: application/json');
echo json_encode([
    'ok'       => true,
    'status'   => 'new_issue',
    'issue_id' => 'issue-test',
    'event_id' => 'event-test',
]);
