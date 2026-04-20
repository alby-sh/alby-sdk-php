<?php

declare(strict_types=1);

use Alby\Report\Dsn;
use Alby\Report\DsnException;

it('parses a standard https DSN', function (): void {
    $dsn = Dsn::parse('https://5e21bf08520734b6734b95f80af40cba6a7efc6cebddd0df@alby.sh/ingest/v1/a195c5dc-01c3-46b3-9db4-b22334c179c9');

    expect($dsn->scheme)->toBe('https');
    expect($dsn->publicKey)->toBe('5e21bf08520734b6734b95f80af40cba6a7efc6cebddd0df');
    expect($dsn->host)->toBe('alby.sh');
    expect($dsn->appId)->toBe('a195c5dc-01c3-46b3-9db4-b22334c179c9');
    expect($dsn->ingestUrl)->toBe('https://alby.sh/api/ingest/v1/events');
    expect($dsn->envelopeUrl)->toBe('https://alby.sh/api/ingest/v1/envelope');
});

it('parses an http DSN (used by staging/dev)', function (): void {
    $dsn = Dsn::parse('http://abcdef0123456789abcdef0123456789@localhost:8000/ingest/v1/aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa');

    expect($dsn->scheme)->toBe('http');
    expect($dsn->host)->toBe('localhost:8000');
    expect($dsn->ingestUrl)->toBe('http://localhost:8000/api/ingest/v1/events');
});

it('accepts a trailing slash', function (): void {
    $dsn = Dsn::parse('https://abcdef0123456789abcdef0123456789@alby.sh/ingest/v1/a195c5dc-01c3-46b3-9db4-b22334c179c9/');
    expect($dsn->appId)->toBe('a195c5dc-01c3-46b3-9db4-b22334c179c9');
});

it('trims whitespace', function (): void {
    $dsn = Dsn::parse("  https://abcdef0123456789abcdef0123456789@alby.sh/ingest/v1/deadbeef-dead-beef-dead-beefdeadbeef  \n");
    expect($dsn->host)->toBe('alby.sh');
});

it('rejects empty strings', function (): void {
    Dsn::parse('');
})->throws(DsnException::class, 'empty');

it('rejects garbage', function (): void {
    Dsn::parse('not-a-dsn');
})->throws(DsnException::class);

it('rejects missing public key', function (): void {
    Dsn::parse('https://@alby.sh/ingest/v1/aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa');
})->throws(DsnException::class);

it('rejects wrong path prefix', function (): void {
    Dsn::parse('https://abcdef0123456789abcdef0123456789@alby.sh/ingest/v2/aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa');
})->throws(DsnException::class);
