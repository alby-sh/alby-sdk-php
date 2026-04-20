# alby/report

[![Packagist](https://img.shields.io/packagist/v/alby/report.svg?color=4f5d95&logo=packagist&logoColor=white)](https://packagist.org/packages/alby/report)
[![Packagist downloads](https://img.shields.io/packagist/dt/alby/report.svg?color=4f5d95)](https://packagist.org/packages/alby/report)
[![PHP](https://img.shields.io/packagist/php-v/alby/report.svg?color=777bb4&logo=php&logoColor=white)](https://packagist.org/packages/alby/report)
[![CI](https://github.com/alby-sh/alby-php/actions/workflows/ci.yml/badge.svg)](https://github.com/alby-sh/alby-php/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](./LICENSE)

Official [Alby](https://alby.sh) error-tracking SDK for PHP 8.2+.

Captures uncaught exceptions, errors, and anything you explicitly report, then
ships them to your Alby project where an AI agent can auto-open a fix task.

## Install

```bash
composer require alby/report
```

Requires PHP 8.2+, `ext-curl`, `ext-json`. No other runtime dependencies.

## Use

```php
use Alby\Report\Alby;

Alby::init([
    'dsn'         => getenv('ALBY_DSN'),  // https://<key>@alby.sh/ingest/v1/<app-id>
    'release'     => '1.4.2',
    'environment' => 'production',
]);

// Uncaught exceptions and fatal errors are sent automatically.

// Manual report:
try {
    doThing();
} catch (\Throwable $e) {
    Alby::captureException($e);
}

// Non-error events:
Alby::captureMessage('Failed to acquire lease', 'warning');

// Enrich the scope:
Alby::setUser(['id' => 'u_412', 'email' => 'ada@example.com']);
Alby::setTag('region', 'eu-west-3');
Alby::setContext('billing_tenant', ['plan' => 'pro', 'seats' => 12]);
Alby::addBreadcrumb(['type' => 'http', 'message' => 'GET /api/orders/42']);

// Before exit (e.g. in a worker):
Alby::flush(2000);
```

The SDK buffers events in memory and ships them on `flush()` or at
`register_shutdown_function` time. Sending is synchronous with a bounded queue
of 100 events; long-running workers should call `Alby::flush()` at the end of
each unit of work.

## Laravel

The package auto-discovers its service provider, so nothing else is needed on
Laravel 5.5+. Publish the config:

```bash
php artisan vendor:publish --tag=alby-report-config
```

Then set your DSN in `.env`:

```
ALBY_DSN=https://<key>@alby.sh/ingest/v1/<app-id>
ALBY_RELEASE=1.4.2
```

### Reporting exceptions

**Laravel 11+ (`bootstrap/app.php`):**

```php
->withExceptions(function (Exceptions $exceptions) {
    $exceptions->report(fn (\Throwable $e) => \Alby\Report\Alby::captureException($e));
})
```

**Laravel ≤10 (`app/Exceptions/Handler.php`):**

```php
public function register(): void
{
    $this->reportable(fn (\Throwable $e) => \Alby\Report\Alby::captureException($e));
}
```

### Optional breadcrumbs

In `config/alby-report.php`:

```php
'breadcrumbs' => [
    'queries' => true,   // DB query breadcrumbs
    'routes'  => true,   // Route-matched breadcrumbs
],
```

## Options

| Option           | Type     | Default                 | Notes |
|------------------|----------|-------------------------|-------|
| `dsn`            | `string` | — (required)            | From your Alby app settings. |
| `release`        | `string` | `''`                    | Build version. Enables release tracking / auto-resolve. |
| `environment`    | `string` | `APP_ENV` / `production`| `production` / `staging` / `dev` / anything. |
| `sample_rate`    | `float`  | `1.0`                   | Fraction of events actually sent, 0..1. |
| `server_name`    | `string` | `gethostname()`         | Attached to every event. |
| `auto_register`  | `bool`   | `true`                  | Install `set_exception_handler` + `set_error_handler` + `register_shutdown_function`. Chains to existing handlers. |
| `debug`          | `bool`   | `false`                 | SDK diagnostics to stderr. |
| `transport`      | `Transport` | `CurlTransport`      | Injectable transport (tests, alt delivery). |
| `breadcrumbs_max`| `int`    | `100`                   | Ring-buffer cap. |

## Wire protocol

This SDK speaks the [Alby Ingest Protocol v1](./PROTOCOL_V1.md). If you're
writing an SDK for a new runtime, start there.

## Links

- Website: [alby.sh](https://alby.sh)
- Report issues: [GitHub Issues](https://github.com/alby-sh/alby-php/issues)
- Other SDKs: [alby-js](https://github.com/alby-sh/alby-js) · [alby-browser](https://github.com/alby-sh/alby-browser) · [alby-python](https://github.com/alby-sh/alby-python)

## License

MIT — © Alby.
