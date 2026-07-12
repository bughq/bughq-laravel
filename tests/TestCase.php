<?php

declare(strict_types=1);

namespace BugHQ\Laravel\Tests;

use BugHQ\Client;
use BugHQ\Laravel\BugHQServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected MockTransport $transport;

    protected function getPackageProviders($app): array
    {
        return [BugHQServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('bughq.project', 'demo');
        $app['config']->set('bughq.key', 'k_test');
        $app['config']->set('bughq.host', 'http://localhost:3108');
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Swap the transport so tests capture payloads instead of sending.
        $this->transport = new MockTransport();
        $this->app->singleton(Client::class, function ($app): Client {
            $config = $app['config']['bughq'];

            return new Client([
                'project' => $config['project'],
                'key' => $config['key'],
                'host' => $config['host'],
                'environment' => 'testing',
                'framework' => 'laravel',
                'sdkName' => 'bughq.laravel',
                'dedupeSeconds' => 0,
            ], $this->transport);
        });
    }
}
