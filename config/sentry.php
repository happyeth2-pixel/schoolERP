<?php

use App\Support\Monitoring\SentryScrubber;

/**
 * Sentry (error monitoring) configuration.
 *
 * @see https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/
 * @see DISASTER_RECOVERY.md's sibling, MONITORING.md, for the full picture: environments,
 *      PII scrubbing, server/backup health checks, and the incident procedure.
 */
return [

    // Deliberately hard-disabled on local/testing regardless of what's in .env: a copied-and-forgotten
    // DSN on a developer's machine must never make dev noise land in the production Sentry project.
    'dsn' => in_array(env('APP_ENV', 'production'), ['local', 'testing'], true)
        ? null
        : env('SENTRY_LARAVEL_DSN'),

    // Render sets RENDER_GIT_COMMIT automatically on every deploy; SENTRY_RELEASE overrides it
    // manually if ever needed (e.g. hotfixing without a new commit-triggered deploy).
    'release' => env('SENTRY_RELEASE', env('RENDER_GIT_COMMIT', 'local-dev')),

    // local / staging / production -- see MONITORING.md for the environment-separation convention.
    // Falls back to APP_ENV, which is exactly what we want.
    'environment' => env('SENTRY_ENVIRONMENT', env('APP_ENV')),

    // The FRONTEND's own Sentry DSN -- a separate project from the backend's (JavaScript errors and
    // PHP exceptions are different platforms in Sentry). Injected server-side into a small inline
    // script in resources/views/app.blade.php, not baked into the built JS bundle, so the same
    // artifact would behave correctly even if this ever moved to a build-once-promote-everywhere
    // pipeline. Same local/testing hard-disable as the backend DSN above.
    'javascript_dsn' => in_array(env('APP_ENV', 'production'), ['local', 'testing'], true)
        ? null
        : env('SENTRY_JS_DSN'),

    'sample_rate' => (float) env('SENTRY_SAMPLE_RATE', 1.0),

    // Disabled outright, not just filtered: the SDK's own source-context feature reads and attaches
    // the actual PHP source lines surrounding each stack frame, as plain text -- which SentryScrubber
    // cannot safely scrub key-by-key the way it scrubs structured data, since it's just source code,
    // not a value with a field name. A real drill against a mock Sentry receiver (see MONITORING.md)
    // confirmed this genuinely leaks any literal sample data that happens to sit in nearby source
    // lines (a seeder, a comment, a test fixture). null fully disables the feature (0 still runs it
    // with zero surrounding lines, which is not the same guarantee).
    'context_lines' => null,

    // Performance tracing is OFF by default -- this is error monitoring, kept simple on purpose.
    // Raise this above 0 later if request-performance tracing becomes worth the added Sentry quota.
    'traces_sample_rate' => (float) env('SENTRY_TRACES_SAMPLE_RATE', 0.0),
    'profiles_sample_rate' => null,

    // Must stay false: this is what would otherwise attach the requester's IP address, cookies and
    // (if authenticated through a framework that populates it) email to every event. The pseudonymous
    // actor context this app attaches instead comes from App\Http\Middleware\AttachMonitoringContext.
    'send_default_pii' => false,

    // The actual scrub: see App\Support\Monitoring\SentryScrubber for exactly what is stripped and why.
    // This runs on every event, in every environment, unconditionally.
    'before_send' => [SentryScrubber::class, 'scrub'],

    // Expected, already-handled "no" responses -- not bugs, so they'd only be noise in Sentry.
    // Framework/library ones Laravel itself renders as an ordinary 4xx without this app doing anything special:
    'ignore_exceptions' => [
        \Illuminate\Auth\AuthenticationException::class,
        \Illuminate\Auth\Access\AuthorizationException::class,
        \Illuminate\Validation\ValidationException::class,
        \Illuminate\Http\Exceptions\ThrottleRequestsException::class,
        \Illuminate\Session\TokenMismatchException::class,
        \Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
        // This app's own "here is why not", rendered with a friendly message -- see bootstrap/app.php:
        \App\Exceptions\DeletionBlockedException::class,
        \App\Exceptions\LockedRecordException::class,
    ],

    'ignore_transactions' => [
        '/up',
    ],

    'breadcrumbs' => [
        'logs' => true,
        'cache' => true,
        'livewire' => false,
        'sql_queries' => true,
        'sql_bindings' => false, // bindings can contain the very data SENSITIVE_KEY_FRAGMENTS exists to hide
        'queue_info' => true,
        'command_info' => true,
        'http_client_requests' => true,
        'notifications' => true,
    ],

    'tracing' => [
        'queue_job_transactions' => false,
        'queue_jobs' => false,
        'sql_queries' => false,
        'sql_bindings' => false,
        'sql_origin' => false,
        'views' => false,
        'livewire' => false,
        'http_client_requests' => false,
        'cache' => false,
        'redis_commands' => false,
        'notifications' => false,
        'missing_routes' => false,
        'default_integrations' => true,
    ],
];
