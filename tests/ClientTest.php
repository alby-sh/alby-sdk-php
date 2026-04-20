<?php

declare(strict_types=1);

use Alby\Report\Alby;
use Alby\Report\Client;
use Alby\Report\Tests\Support\FakeTransport;

const TEST_DSN = 'https://abcdef0123456789abcdef0123456789@alby.sh/ingest/v1/aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';

function makeClient(array $overrides = []): array
{
    $t = new FakeTransport();
    $c = new Client(array_merge([
        'dsn'           => TEST_DSN,
        'release'       => '1.0.0',
        'environment'   => 'test',
        'server_name'   => 'host-1',
        'transport'     => $t,
        'auto_register' => false,
    ], $overrides));
    return [$c, $t];
}

it('captures an exception through the transport', function (): void {
    [$c, $t] = makeClient();
    $eventId = $c->captureException(new RuntimeException('nope'));

    expect($eventId)->toBeString()->toHaveLength(36);
    expect($t->sent)->toHaveCount(1);
    $p = $t->last();
    expect($p['platform'])->toBe('php');
    expect($p['level'])->toBe('error');
    expect($p['release'])->toBe('1.0.0');
    expect($p['environment'])->toBe('test');
    expect($p['server_name'])->toBe('host-1');
    expect($p['exception']['type'])->toBe('RuntimeException');
    expect($p['exception']['value'])->toBe('nope');
    expect($p['exception']['frames'])->toBeArray()->not->toBeEmpty();
    expect($p['contexts']['runtime'])->toBe(['name' => 'php', 'version' => PHP_VERSION]);
});

it('captureMessage emits a message-only event', function (): void {
    [$c, $t] = makeClient();
    $c->captureMessage('hello', 'warning');
    $p = $t->last();
    expect($p['message'])->toBe('hello');
    expect($p['level'])->toBe('warning');
    expect($p)->not->toHaveKey('exception');
});

it('propagates setUser / setTag / setContext', function (): void {
    [$c, $t] = makeClient();
    $c->setUser(['id' => 'u_1', 'email' => 'a@b.co']);
    $c->setTag('region', 'eu-west');
    $c->setContext('billing', ['plan' => 'pro']);
    $c->captureMessage('hi');
    $p = $t->last();
    expect($p['contexts']['user'])->toBe(['id' => 'u_1', 'email' => 'a@b.co']);
    expect($p['contexts']['billing'])->toBe(['plan' => 'pro']);
    expect($p['tags'])->toBe(['region' => 'eu-west']);
});

it('addBreadcrumb attaches to the next event', function (): void {
    [$c, $t] = makeClient();
    $c->addBreadcrumb(['type' => 'http', 'message' => 'GET /x']);
    $c->addBreadcrumb(['type' => 'query', 'message' => 'select 1']);
    $c->captureMessage('m');
    $p = $t->last();
    expect($p['breadcrumbs'])->toHaveCount(2);
    expect($p['breadcrumbs'][0]['message'])->toBe('GET /x');
    expect($p['breadcrumbs'][0])->toHaveKey('timestamp');
});

it('respects sample_rate = 0 (drops everything)', function (): void {
    [$c, $t] = makeClient(['sample_rate' => 0.0]);
    $id = $c->captureMessage('dropped');
    expect($id)->toBeNull();
    expect($t->sent)->toHaveCount(0);
});

it('respects sample_rate = 1 (sends everything)', function (): void {
    [$c, $t] = makeClient(['sample_rate' => 1.0]);
    for ($i = 0; $i < 10; $i++) $c->captureMessage("m{$i}");
    expect($t->sent)->toHaveCount(10);
});

it('Alby facade delegates to the default client', function (): void {
    $t = new FakeTransport();
    Alby::init([
        'dsn'           => TEST_DSN,
        'transport'     => $t,
        'auto_register' => false,
    ]);
    $id = Alby::captureMessage('facade hi', 'info');
    expect($id)->toBeString();
    expect($t->sent)->toHaveCount(1);
    expect($t->last()['message'])->toBe('facade hi');
});

it('Alby::flush is safe without init', function (): void {
    // After reset() in beforeEach, no client exists.
    expect(Alby::flush())->toBeTrue();
});

it('onBeforeSend can mutate or drop events', function (): void {
    [$c, $t] = makeClient();
    $c->onBeforeSend(function (array $p): ?array {
        if (($p['message'] ?? '') === 'drop-me') return null;
        $p['tags'] = ($p['tags'] ?? []) + ['hook' => 'yes'];
        return $p;
    });
    $c->captureMessage('drop-me');
    $c->captureMessage('keep-me');
    expect($t->sent)->toHaveCount(1);
    expect($t->last()['message'])->toBe('keep-me');
    expect($t->last()['tags']['hook'])->toBe('yes');
});
