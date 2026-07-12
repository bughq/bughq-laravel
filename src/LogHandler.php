<?php

declare(strict_types=1);

namespace BugHQ\Laravel;

use BugHQ\Client;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * Monolog handler backing the `bughq` log channel: records at or above the
 * channel level become bughq events (exceptions in context are captured with
 * their stack), anything below becomes a breadcrumb.
 */
final class LogHandler extends AbstractProcessingHandler
{
    public function __construct(private readonly Client $client, int|string|Level $level = Level::Error)
    {
        parent::__construct($level, true);
    }

    protected function write(LogRecord $record): void
    {
        $context = $record->context;
        $exception = $context['exception'] ?? null;
        unset($context['exception']);

        if ($exception instanceof \Throwable) {
            $this->client->captureException($exception, $context, $this->mapLevel($record->level));

            return;
        }

        $this->client->captureMessage($record->message, $this->mapLevel($record->level), $context);
    }

    private function mapLevel(Level $level): string
    {
        return match (true) {
            $level->value >= Level::Critical->value => 'fatal',
            $level->value >= Level::Error->value => 'error',
            $level->value >= Level::Warning->value => 'warning',
            default => 'info',
        };
    }
}
