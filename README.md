# bughq for Laravel

Privacy-first error tracking for Laravel apps, backed by
[`bughq/bughq`](https://github.com/bughq/bughq-php) and [bughq](https://bughq.org).

Out of the box:

- **Reported exceptions** are captured through Laravel's exception handler
  (after your `dontReport` filtering), with route, request, and
  authenticated-user context attached.
- **Failed queue jobs** are captured with job name, queue, connection, and
  attempt count.
- **SQL queries and log messages** are recorded as breadcrumbs, so every
  event arrives with the trail that led to it.
- A **`bughq` log channel** turns `Log::channel('bughq')->error(...)` (or a
  stack channel) into bughq events.

## Install

Requires PHP 8.4+ and Laravel 11 or 12.

```bash
composer require bughq/bughq-laravel
```

> Until the packages are on Packagist, add the VCS repositories first:
>
> ```bash
> composer config repositories.bughq-php vcs https://github.com/bughq/bughq-php
> composer config repositories.bughq-laravel vcs https://github.com/bughq/bughq-laravel
> ```

The service provider and `BugHQ` facade are auto-discovered.

## Configure

```bash
php artisan vendor:publish --tag=bughq-config
```

```dotenv
BUGHQ_PROJECT=acme-api
BUGHQ_KEY=pk_...
# or a single DSN:
# BUGHQ_DSN=https://pk_...@bughq.org/acme-api
```

`environment` defaults to the app environment; set `BUGHQ_RELEASE` to tag
deploys. See `config/bughq.php` for breadcrumb/capture toggles,
`sample_rate`, and `ignore_exceptions`.

## Manual capture

```php
use BugHQ\Laravel\Facades\BugHQ;

BugHQ::captureException($e, ['orderId' => $order->id]);
BugHQ::captureMessage('imports finished', 'info');
BugHQ::setTag('tenant', $tenant->slug);
BugHQ::setContext('order', ['id' => $order->id, 'total' => $order->total]);
BugHQ::addBreadcrumb(['category' => 'payment', 'message' => 'charge submitted']);
```

## Log channel

```php
// config/logging.php
'channels' => [
    'bughq' => ['driver' => 'bughq', 'level' => 'error'],

    // or fan out alongside your existing channel:
    'stack' => ['driver' => 'stack', 'channels' => ['single', 'bughq']],
],
```

Log records carrying an `exception` in context are captured with their full
stack trace.

## License

MIT
