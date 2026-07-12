<?php

declare(strict_types=1);

namespace BugHQ\Laravel\Facades;

use BugHQ\Client;
use Illuminate\Support\Facades\Facade;

/**
 * @method static bool captureException(\Throwable $e, array $extra = [], string $level = 'error')
 * @method static bool captureMessage(string $message, string $level = 'info', array $extra = [])
 * @method static void addBreadcrumb(array|\BugHQ\Breadcrumb $crumb)
 * @method static void setUser(?array $user)
 * @method static void setTag(string $key, string $value)
 * @method static void setContext(string $name, ?array $context)
 * @method static void setExtra(string $key, mixed $value)
 * @method static void setLevel(?string $level)
 * @method static void setFingerprint(?array $fingerprint)
 *
 * @see Client
 */
final class BugHQ extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Client::class;
    }
}
