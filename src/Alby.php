<?php

declare(strict_types=1);

namespace Alby\Report;

use Throwable;

/**
 * Static facade over a single default {@see Client} instance. This is the
 * entry-point users almost always want.
 *
 *   Alby::init(['dsn' => $dsn, 'release' => '1.0.0']);
 *   Alby::captureException($e);
 *
 * Multi-tenant hosts can skip the facade and manage `new Client(...)`
 * instances directly — everything the facade does is a thin delegation.
 */
final class Alby
{
    private static ?Client $client = null;
    private static bool $handlersInstalled = false;

    /** @var callable|null previous exception handler, chained when we install ours */
    private static $previousExceptionHandler = null;

    /** @var callable|null previous error handler, chained when we install ours */
    private static $previousErrorHandler = null;

    /**
     * @param array<string, mixed> $options see Client::__construct + 'auto_register' bool (default true)
     */
    public static function init(array $options): Client
    {
        self::$client = new Client($options);

        $autoRegister = (bool) ($options['auto_register'] ?? true);
        if ($autoRegister && !self::$handlersInstalled) {
            self::installHandlers();
            self::$handlersInstalled = true;
        }
        return self::$client;
    }

    public static function getClient(): ?Client
    {
        return self::$client;
    }

    /** @internal Used by tests to reset static state. */
    public static function reset(): void
    {
        self::$client = null;
        self::$handlersInstalled = false;
        self::$previousExceptionHandler = null;
        self::$previousErrorHandler = null;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public static function captureException(Throwable $e, array $overrides = []): ?string
    {
        return self::$client?->captureException($e, $overrides);
    }

    public static function captureMessage(string $message, string $level = 'info'): ?string
    {
        return self::$client?->captureMessage($message, $level);
    }

    /**
     * @param array<string, mixed>|null $user
     */
    public static function setUser(?array $user): void
    {
        self::$client?->setUser($user);
    }

    public static function setTag(string $key, string $value): void
    {
        self::$client?->setTag($key, $value);
    }

    /**
     * @param array<string, mixed>|null $ctx
     */
    public static function setContext(string $key, ?array $ctx): void
    {
        self::$client?->setContext($key, $ctx);
    }

    /**
     * @param array<string, mixed> $crumb
     */
    public static function addBreadcrumb(array $crumb): void
    {
        self::$client?->addBreadcrumb($crumb);
    }

    public static function flush(int $timeoutMs = 2000): bool
    {
        return self::$client?->flush($timeoutMs) ?? true;
    }

    // -- Handlers --------------------------------------------------------------

    /**
     * Install global handlers (exceptions, errors, shutdown/fatal).
     *
     * Chains gracefully with any previously-registered handlers — ours run
     * first (capture), then we defer to the chained handler.
     */
    private static function installHandlers(): void
    {
        self::$previousExceptionHandler = set_exception_handler(static function (Throwable $e): void {
            self::$client?->captureException($e, ['level' => 'fatal']);
            self::$client?->flush(2000);

            if (self::$previousExceptionHandler !== null) {
                (self::$previousExceptionHandler)($e);
            } else {
                // PHP will exit with a non-zero status anyway — re-throw so the
                // default handler prints the stack to stderr like normal.
                throw $e;
            }
        });

        self::$previousErrorHandler = set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0): bool {
            // Respect error_reporting() — don't capture suppressed errors.
            if (!(error_reporting() & $severity)) {
                return self::callPreviousErrorHandler($severity, $message, $file, $line);
            }

            // Fatal-class errors: capture and let PHP handle termination.
            $isFatal = (bool) ($severity & (E_ERROR | E_PARSE | E_CORE_ERROR | E_CORE_WARNING | E_COMPILE_ERROR | E_COMPILE_WARNING | E_USER_ERROR));
            $level   = $isFatal ? 'fatal' : self::errorSeverityToLevel($severity);

            $err = new \ErrorException($message, 0, $severity, $file, $line);
            self::$client?->captureException($err, ['level' => $level]);

            // Return value: false keeps PHP's default handling (important for
            // fatal errors to actually halt); chain to previous non-fatal.
            return self::callPreviousErrorHandler($severity, $message, $file, $line);
        });

        register_shutdown_function(static function (): void {
            $err = error_get_last();
            if (
                $err !== null &&
                isset($err['type']) &&
                ($err['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR))
            ) {
                $ex = new \ErrorException(
                    (string) ($err['message'] ?? 'fatal error'),
                    0,
                    (int) $err['type'],
                    (string) ($err['file'] ?? ''),
                    (int) ($err['line'] ?? 0),
                );
                self::$client?->captureException($ex, ['level' => 'fatal']);
            }
            // Always flush buffered events on shutdown.
            self::$client?->flush(2000);
        });
    }

    private static function callPreviousErrorHandler(int $severity, string $message, string $file, int $line): bool
    {
        if (self::$previousErrorHandler === null) {
            return false;
        }
        $res = (self::$previousErrorHandler)($severity, $message, $file, $line);
        return is_bool($res) ? $res : false;
    }

    private static function errorSeverityToLevel(int $severity): string
    {
        return match (true) {
            (bool) ($severity & (E_ERROR | E_USER_ERROR | E_CORE_ERROR | E_COMPILE_ERROR | E_RECOVERABLE_ERROR)) => 'error',
            (bool) ($severity & (E_WARNING | E_USER_WARNING | E_CORE_WARNING | E_COMPILE_WARNING)) => 'warning',
            (bool) ($severity & (E_NOTICE | E_USER_NOTICE | E_STRICT | E_DEPRECATED | E_USER_DEPRECATED)) => 'info',
            default => 'error',
        };
    }
}
