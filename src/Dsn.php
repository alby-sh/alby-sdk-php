<?php

declare(strict_types=1);

namespace Alby\Report;

/**
 * Parsed DSN value object.
 *
 * Format:  https://<public_key>@<host>/ingest/v1/<app_id>
 *
 * Only the public key is required on the wire; the app id lives in the path
 * for human-readability and debugging.
 */
final class Dsn
{
    /**
     * Matches `https?://<key>@<host>/ingest/v1/<app_id>[/]`.
     * - key: >=16 chars of [A-Za-z0-9]
     * - host: anything except `/`
     * - app id: hex + dashes, >=8 chars (accepts UUIDs and slugs)
     */
    private const DSN_RE = '~^(?P<scheme>https?)://(?P<key>[A-Za-z0-9]{16,})@(?P<host>[^/]+)/ingest/v1/(?P<app>[0-9a-f-]{8,})/?$~i';

    public readonly string $scheme;
    public readonly string $publicKey;
    public readonly string $host;
    public readonly string $appId;
    public readonly string $ingestUrl;
    public readonly string $envelopeUrl;

    private function __construct(string $scheme, string $publicKey, string $host, string $appId)
    {
        $this->scheme      = $scheme;
        $this->publicKey   = $publicKey;
        $this->host        = $host;
        $this->appId       = $appId;
        $this->ingestUrl   = "{$scheme}://{$host}/api/ingest/v1/events";
        $this->envelopeUrl = "{$scheme}://{$host}/api/ingest/v1/envelope";
    }

    public static function parse(string $dsn): self
    {
        $dsn = trim($dsn);
        if ($dsn === '') {
            throw new DsnException('empty');
        }
        if (!preg_match(self::DSN_RE, $dsn, $m)) {
            throw new DsnException('unrecognised format. Expected https://<key>@<host>/ingest/v1/<app-id>');
        }

        return new self(
            strtolower($m['scheme']),
            $m['key'],
            $m['host'],
            $m['app'],
        );
    }
}
