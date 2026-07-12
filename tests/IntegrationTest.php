<?php

declare(strict_types=1);

namespace BugHQ\Laravel\Tests;

use BugHQ\Client;
use BugHQ\Laravel\Facades\BugHQ;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

final class IntegrationTest extends TestCase
{
    public function testClientIsBoundAsASingletonWithLaravelIdentity(): void
    {
        $client = $this->app->make(Client::class);

        self::assertSame($client, $this->app->make(Client::class));
        self::assertSame($client, $this->app->make('bughq'));
        self::assertSame('demo', $client->config->project);
        self::assertSame('laravel', $client->config->framework);
        self::assertSame('bughq.laravel', $client->config->sdkName);
    }

    public function testReportedExceptionsAreCaptured(): void
    {
        $handler = $this->app->make(ExceptionHandler::class);
        $handler->report(new \RuntimeException('handler boom'));

        self::assertNotEmpty($this->transport->payloads);
        $payload = $this->transport->last();
        self::assertSame('RuntimeException', $payload['type']);
        self::assertSame('handler boom', $payload['message']);
        self::assertSame('laravel', $payload['framework']);
        self::assertSame('bughq.laravel', $payload['sdk']['name']);
        self::assertStringContainsString('    at ', $payload['stack']);
    }

    public function testLogMessagesBecomeBreadcrumbs(): void
    {
        Event::dispatch(new MessageLogged('info', 'cache warmed', ['keys' => 12]));
        Event::dispatch(new MessageLogged('warning', 'slow response', []));

        $this->app->make(ExceptionHandler::class)->report(new \RuntimeException('after logs'));

        $crumbs = $this->transport->last()['breadcrumbs'] ?? [];
        $messages = array_column($crumbs, 'message');
        self::assertContains('cache warmed', $messages);
        self::assertContains('slow response', $messages);
    }

    public function testErrorLevelLogsDoNotDuplicateAsBreadcrumbs(): void
    {
        Event::dispatch(new MessageLogged('error', 'this becomes an event elsewhere', []));

        $this->app->make(ExceptionHandler::class)->report(new \RuntimeException('x'));

        $messages = array_column($this->transport->last()['breadcrumbs'] ?? [], 'message');
        self::assertNotContains('this becomes an event elsewhere', $messages);
    }

    public function testQueueFailuresAreCapturedOnceWithJobContext(): void
    {
        $job = $this->createMock(Job::class);
        $job->method('resolveName')->willReturn('App\\Jobs\\SendInvoice');
        $job->method('getQueue')->willReturn('emails');
        $job->method('attempts')->willReturn(3);

        $exception = new \RuntimeException('job blew up');

        // The worker's real sequence: JobFailed fires first (context only -
        // capturing there too would double-report), then the exception flows
        // through the handler's report pipeline, which captures ONCE.
        Event::dispatch(new JobFailed('redis', $job, $exception));
        self::assertCount(0, $this->transport->payloads, 'JobFailed alone must not capture');

        $this->app->make(ExceptionHandler::class)->report($exception);

        self::assertCount(1, $this->transport->payloads);
        $payload = $this->transport->last();
        self::assertSame('job blew up', $payload['message']);
        self::assertSame('App\\Jobs\\SendInvoice', $payload['contexts']['job']['name']);
        self::assertSame('emails', $payload['contexts']['job']['queue']);
        self::assertSame(3, $payload['contexts']['job']['attempts']);
        self::assertSame('true', $payload['tags']['queue_failure']);
        $messages = array_column($payload['breadcrumbs'] ?? [], 'message');
        self::assertContains('App\\Jobs\\SendInvoice failed', $messages);
    }

    public function testJobProcessingResetsScopeAndBreadcrumbs(): void
    {
        $client = $this->app->make(\BugHQ\Client::class);
        $client->setUser(['id' => 1, 'email' => 'previous-job@user.test']);
        $client->setContext('job', ['name' => 'App\\Jobs\\OldJob']);
        $client->addBreadcrumb(['message' => 'stale crumb from previous job']);

        $job = $this->createMock(Job::class);
        Event::dispatch(new \Illuminate\Queue\Events\JobProcessing('redis', $job));

        $this->app->make(ExceptionHandler::class)->report(new \RuntimeException('fresh job error'));

        $payload = $this->transport->last();
        self::assertArrayNotHasKey('user', $payload, 'previous job user must not leak');
        self::assertArrayNotHasKey('job', $payload['contexts'] ?? [], 'previous job context must not leak');
        $messages = array_column($payload['breadcrumbs'] ?? [], 'message');
        self::assertNotContains('stale crumb from previous job', $messages);
    }

    public function testDsnOnlyConfigKeepsTheDsnHost(): void
    {
        $this->app['config']->set('bughq', array_merge($this->app['config']['bughq'], [
            'dsn' => 'https://pk_abc@errors.selfhosted.dev/acme-api',
            'project' => null,
            'key' => null,
            'host' => null, // env('BUGHQ_HOST') unset - must NOT default over the DSN
        ]));

        // Re-register so the provider's own factory (not the test double from
        // setUp) builds the client from the DSN-only config.
        (new \BugHQ\Laravel\BugHQServiceProvider($this->app))->register();
        $this->app->forgetInstance(\BugHQ\Client::class);
        $client = $this->app->make(\BugHQ\Client::class);

        self::assertSame('https://errors.selfhosted.dev', $client->config->host);
        self::assertSame('acme-api', $client->config->project);
        self::assertSame('pk_abc', $client->config->key);
        self::assertTrue($client->config->enabled);
    }

    public function testBughqChannelStillResolvesWhenDisabled(): void
    {
        $this->app['config']->set('bughq.enabled', false);
        $this->app['config']->set('logging.channels.bughq', ['driver' => 'bughq', 'level' => 'error']);

        // Re-boot the provider with the disabled config: the driver must
        // still be registered so channels referencing it keep resolving.
        $provider = new \BugHQ\Laravel\BugHQServiceProvider($this->app);
        $provider->boot();

        $logger = Log::channel('bughq');
        $logger->error('dropped silently');

        self::assertNotNull($logger);
    }

    public function testUnauthenticatedRequestClearsAPreviousUser(): void
    {
        $client = $this->app->make(\BugHQ\Client::class);
        $client->setUser(['id' => 42, 'email' => 'request-one@user.test']);

        // Request two: unauthenticated - the reported event must NOT carry
        // request one's identity.
        $this->app->make(ExceptionHandler::class)->report(new \RuntimeException('second request error'));

        $payload = $this->transport->last();
        self::assertArrayNotHasKey('user', $payload);
    }

    public function testBughqLogChannelCapturesEvents(): void
    {
        $this->app['config']->set('logging.channels.bughq', ['driver' => 'bughq', 'level' => 'error']);

        Log::channel('bughq')->error('channel boom', ['orderId' => 'ord_9']);

        self::assertNotEmpty($this->transport->payloads);
        $payload = $this->transport->last();
        self::assertSame('Message', $payload['type']);
        self::assertSame('channel boom', $payload['message']);
        self::assertSame('error', $payload['level']);
        self::assertSame('ord_9', $payload['extra']['orderId']);
    }

    public function testBughqLogChannelCapturesExceptionsWithStack(): void
    {
        $this->app['config']->set('logging.channels.bughq', ['driver' => 'bughq', 'level' => 'error']);

        Log::channel('bughq')->error('ignored text', ['exception' => new \LogicException('from log context')]);

        $payload = $this->transport->last();
        self::assertSame('LogicException', $payload['type']);
        self::assertSame('from log context', $payload['message']);
        self::assertStringContainsString('    at ', $payload['stack']);
    }

    public function testFacadeProxiesToTheClient(): void
    {
        BugHQ::setTag('plan', 'pro');
        BugHQ::captureMessage('via facade', 'warning');

        $payload = $this->transport->last();
        self::assertSame('via facade', $payload['message']);
        self::assertSame('pro', $payload['tags']['plan']);
    }

    public function testConfigIsPublishable(): void
    {
        self::assertSame('demo', $this->app['config']['bughq.project']);
        self::assertTrue((bool) $this->app['config']['bughq.capture.exceptions']);
    }
}
