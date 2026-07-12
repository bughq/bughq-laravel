<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Credentials
    |--------------------------------------------------------------------------
    |
    | Project id + public ingest key from your bughq dashboard, or a single
    | DSN of the form https://<key>@<host>/<project>. The ingest key is a
    | public, revocable identifier - it is safe in client code and .env.
    |
    */

    'dsn' => env('BUGHQ_DSN'),
    'project' => env('BUGHQ_PROJECT'),
    'key' => env('BUGHQ_KEY'),
    'host' => env('BUGHQ_HOST', 'https://bughq.org'),

    /*
    |--------------------------------------------------------------------------
    | Event metadata
    |--------------------------------------------------------------------------
    */

    'release' => env('BUGHQ_RELEASE'),
    'environment' => env('BUGHQ_ENVIRONMENT'), // defaults to app env

    /*
    |--------------------------------------------------------------------------
    | Behavior
    |--------------------------------------------------------------------------
    */

    'enabled' => env('BUGHQ_ENABLED', true),
    'sample_rate' => env('BUGHQ_SAMPLE_RATE', 1.0),

    // Exceptions never reported (instanceof match). Laravel's dontReport
    // list applies first - these are on top of it.
    'ignore_exceptions' => [
        // \Illuminate\Auth\AuthenticationException::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Breadcrumbs
    |--------------------------------------------------------------------------
    */

    'breadcrumbs' => [
        // Record SQL queries (bindings are NOT included).
        'sql_queries' => env('BUGHQ_BREADCRUMBS_SQL', true),
        // Record log messages below the error level.
        'logs' => env('BUGHQ_BREADCRUMBS_LOGS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Capture
    |--------------------------------------------------------------------------
    */

    'capture' => [
        // Report reported exceptions (via the exception handler).
        'exceptions' => true,
        // Report failed queue jobs with job context.
        'queue_failures' => true,
        // Attach the authenticated user (id + email) to events.
        'user' => true,
    ],

];
