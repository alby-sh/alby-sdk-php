<?php

/**
 * Default config for alby/report. Publish with:
 *
 *   php artisan vendor:publish --tag=alby-report-config
 */

return [
    // Your Alby DSN — leave empty in local to disable the SDK entirely.
    'dsn' => env('ALBY_DSN', ''),

    // Application release (used for release tracking + auto-resolve).
    'release' => env('ALBY_RELEASE', ''),

    // Override the environment string sent to Alby. Defaults to app()->environment().
    'environment' => env('ALBY_ENV', null),

    // Fraction of events to actually send, 0..1.
    'sample_rate' => (float) env('ALBY_SAMPLE_RATE', 1.0),

    // Server hostname (defaults to gethostname()).
    'server_name' => env('ALBY_SERVER_NAME', null),

    // Enable SDK-internal debug logging to stderr.
    'debug' => (bool) env('ALBY_DEBUG', false),

    // In Laravel the framework already owns the exception handler. Leave
    // auto_register = false and wire `Alby::captureException` into
    // bootstrap/app.php's `->withExceptions(...)` or app/Exceptions/Handler.php.
    'auto_register' => (bool) env('ALBY_AUTO_REGISTER', false),

    'breadcrumbs' => [
        'queries' => (bool) env('ALBY_BREADCRUMBS_QUERIES', false),
        'routes'  => (bool) env('ALBY_BREADCRUMBS_ROUTES', false),
    ],
];
