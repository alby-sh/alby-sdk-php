<?php

declare(strict_types=1);

namespace Alby\Report\Laravel;

use Alby\Report\Alby;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

/**
 * Laravel service provider. Does four things:
 *   1. Merge `config/alby-report.php` defaults into the app's config.
 *   2. Publish the config stub so users can `vendor:publish --tag=alby-report-config`.
 *   3. Call Alby::init() on boot, unless the user has already done so.
 *   4. Optionally wire query + route breadcrumbs off the dispatcher.
 */
class ServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom($this->defaultConfigPath(), 'alby-report');
    }

    public function boot(): void
    {
        // Publish the config so users can customise.
        if ($this->app->runningInConsole()) {
            $this->publishes([
                $this->defaultConfigPath() => $this->app->configPath('alby-report.php'),
            ], 'alby-report-config');
        }

        $config = (array) $this->app['config']->get('alby-report', []);

        // Disable entirely when no DSN is set — allows local / testing envs.
        $dsn = is_string($config['dsn'] ?? null) ? $config['dsn'] : '';
        if ($dsn === '') {
            return;
        }

        // Only auto-init if the app hasn't already done so manually.
        if (Alby::getClient() === null) {
            Alby::init([
                'dsn'           => $dsn,
                'release'       => (string) ($config['release'] ?? ''),
                'environment'   => (string) ($config['environment'] ?? $this->app->environment()),
                'sample_rate'   => (float) ($config['sample_rate'] ?? 1.0),
                'server_name'   => (string) ($config['server_name'] ?? gethostname() ?: ''),
                'debug'         => (bool) ($config['debug'] ?? false),
                // In Laravel the framework wires its own exception handler; we
                // don't want to clobber it by default.
                'auto_register' => (bool) ($config['auto_register'] ?? false),
            ]);
        }

        if ((bool) ($config['breadcrumbs']['queries'] ?? false)) {
            $this->wireQueryBreadcrumbs();
        }
        if ((bool) ($config['breadcrumbs']['routes'] ?? false)) {
            $this->wireRouteBreadcrumbs();
        }
    }

    private function defaultConfigPath(): string
    {
        return __DIR__ . '/config/alby-report.php';
    }

    private function wireQueryBreadcrumbs(): void
    {
        if (!class_exists(QueryExecuted::class)) {
            return;
        }
        $this->app['events']->listen(QueryExecuted::class, static function (QueryExecuted $event): void {
            Alby::addBreadcrumb([
                'type'     => 'query',
                'category' => 'db',
                'message'  => $event->sql,
                'data'     => [
                    'connection' => $event->connectionName,
                    'time_ms'    => $event->time,
                ],
            ]);
        });
    }

    private function wireRouteBreadcrumbs(): void
    {
        if (!class_exists(RouteMatched::class)) {
            return;
        }
        $this->app['events']->listen(RouteMatched::class, static function (RouteMatched $event): void {
            Alby::addBreadcrumb([
                'type'     => 'navigation',
                'category' => 'route',
                'message'  => $event->route->uri(),
                'data'     => [
                    'name'   => $event->route->getName(),
                    'action' => $event->route->getActionName(),
                ],
            ]);
        });
    }

    /**
     * Support the 'application' type-hint on provider hooks (Laravel 11+).
     */
    public function provides(): array
    {
        return [];
    }

    protected function getApp(): ?Application
    {
        return $this->app instanceof Application ? $this->app : null;
    }
}
