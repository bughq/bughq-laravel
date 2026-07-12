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

    public function testQueueFailuresAreCapturedWithJobContext(): void
    {
        $job = $this->createMock(Job::class);
        $job->method('resolveName')->willReturn('App\\Jobs\\SendInvoice');
        $job->method('getQueue')->willReturn('emails');
        $job->method('attempts')->willReturn(3);

        Event::dispatch(new JobFailed('redis', $job, new \RuntimeException('job blew up')));

        self::assertNotEmpty($this->transport->payloads);
        $payload = $this->transport->last();
        self::assertSame('job blew up', $payload['message']);
        self::assertTrue($payload['extra']['queueFailure']);
        self::assertSame('App\\Jobs\\SendInvoice', $payload['contexts']['job']['name']);
        self::assertSame('emails', $payload['contexts']['job']['queue']);
        self::assertSame(3, $payload['contexts']['job']['attempts']);
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
