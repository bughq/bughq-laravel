<?php

declare(strict_types=1);

namespace BugHQ\Laravel;

use BugHQ\Breadcrumb;
use BugHQ\Client;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Wires bughq into Laravel: reported exceptions, failed queue jobs, SQL and
 * log breadcrumbs, and authenticated-user context - configured from
 * `config/bughq.php` / `.env`.
 */
class BugHQServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/bughq.php', 'bughq');

        $this->app->singleton(Client::class, function ($app): Client {
            $config = $app['config']['bughq'] ?? [];

            return new Client(array_filter([
                'dsn' => $config['dsn'] ?? null,
                'project' => $config['project'] ?? null,
                'key' => $config['key'] ?? null,
                'host' => $config['host'] ?? null,
                'release' => $config['release'] ?? null,
                'environment' => $config['environment'] ?? $app->environment(),
                'enabled' => (bool) ($config['enabled'] ?? true),
                'sampleRate' => (float) ($config['sample_rate'] ?? 1.0),
                'ignoreExceptions' => $config['ignore_exceptions'] ?? [],
                'framework' => 'laravel',
                'sdkName' => 'bughq.laravel',
            ], static fn ($v) => $v !== null));
        });

        $this->app->alias(Client::class, 'bughq');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/bughq.php' => $this->app->configPath('bughq.php'),
        ], 'bughq-config');

        $config = $this->app['config']['bughq'] ?? [];
        if (!($config['enabled'] ?? true)) {
            return;
        }

        if ($config['capture']['exceptions'] ?? true) {
            $this->captureReportedExceptions();
        }

        if ($config['capture']['queue_failures'] ?? true) {
            $this->captureQueueFailures();
        }

        if ($config['breadcrumbs']['sql_queries'] ?? true) {
            $this->recordQueryBreadcrumbs();
        }

        if ($config['breadcrumbs']['logs'] ?? true) {
            $this->recordLogBreadcrumbs();
        }

        // `bughq` log channel: `'channels' => ['bughq' => ['driver' => 'bughq']]`.
        $this->app->make('log')->extend('bughq', function ($app, array $channelConfig) {
            $handler = new LogHandler($app->make(Client::class), $channelConfig['level'] ?? 'error');

            return new \Monolog\Logger('bughq', [$handler]);
        });
    }

    /**
     * Report exceptions as they flow through Laravel's exception handler.
     * `reportable()` runs after `dontReport`/`shouldntReport` filtering, so
     * bughq sees exactly what the app considers report-worthy.
     */
    private function captureReportedExceptions(): void
    {
        $this->callAfterResolving(ExceptionHandler::class, function (ExceptionHandler $handler): void {
            if (!method_exists($handler, 'reportable')) {
                return;
            }

            $handler->reportable(function (\Throwable $e): void {
                $client = $this->app->make(Client::class);
                $this->attachRequestContext($client);
                $this->attachAuthenticatedUser($client);
                $client->captureException($e);
            });
        });
    }

    private function captureQueueFailures(): void
    {
        Event::listen(JobFailed::class, function (JobFailed $event): void {
            $client = $this->app->make(Client::class);
            $client->setContext('job', [
                'name' => $event->job->resolveName(),
                'connection' => $event->connectionName,
                'queue' => $event->job->getQueue(),
                'attempts' => $event->job->attempts(),
            ]);
            $client->captureException($event->exception, ['queueFailure' => true]);
        });
    }

    private function recordQueryBreadcrumbs(): void
    {
        Event::listen(QueryExecuted::class, function (QueryExecuted $event): void {
            $this->app->make(Client::class)->addBreadcrumb(new Breadcrumb(
                message: $event->sql,
                type: 'query',
                category: 'sql.query',
                data: [
                    'connection' => $event->connectionName,
                    'timeMs' => $event->time,
                ],
            ));
        });
    }

    private function recordLogBreadcrumbs(): void
    {
        Event::listen(MessageLogged::class, function (MessageLogged $event): void {
            // Errors and worse become events via the exception handler / log
            // channel - as breadcrumbs they would only duplicate.
            if (\in_array($event->level, ['error', 'critical', 'alert', 'emergency'], true)) {
                return;
            }

            $this->app->make(Client::class)->addBreadcrumb(new Breadcrumb(
                message: $event->message,
                type: 'log',
                category: 'log.' . $event->level,
                level: $event->level === 'warning' ? 'warning' : 'info',
                data: $event->context !== [] ? $event->context : null,
            ));
        });
    }

    private function attachRequestContext(Client $client): void
    {
        try {
            if (!$this->app->bound('request') || $this->app->runningInConsole()) {
                return;
            }
            $request = $this->app->make('request');
            $route = $request->route();

            $client->setContext('request', array_filter([
                'method' => $request->method(),
                'url' => $request->fullUrl(),
                'route' => $route?->getName() ?? $route?->uri(),
                'ip' => $request->ip(),
                'userAgent' => $request->userAgent(),
            ], static fn ($v) => $v !== null));
        } catch (\Throwable) {
            // reporting must never break the app
        }
    }

    private function attachAuthenticatedUser(Client $client): void
    {
        $config = $this->app['config']['bughq'] ?? [];
        if (!($config['capture']['user'] ?? true)) {
            return;
        }

        try {
            $guard = $this->app->make('auth')->guard();
            $user = $guard->check() ? $guard->user() : null;
            if ($user === null) {
                return;
            }

            $client->setUser(array_filter([
                'id' => method_exists($user, 'getAuthIdentifier') ? $user->getAuthIdentifier() : null,
                'email' => $user->email ?? null,
            ], static fn ($v) => $v !== null));
        } catch (\Throwable) {
            // no auth configured - skip
        }
    }
}
