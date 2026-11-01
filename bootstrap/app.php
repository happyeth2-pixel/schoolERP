<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Registered by hand (not via withRouting's own `channels:` option) specifically so the
    // broadcasting auth endpoint -- hit once per browser tab whenever Echo opens its private
    // notification channel -- carries the same real-signed-in-user requirement and the same
    // 'notifications' rate limiter as the rest of the live-update surface (see
    // RateLimitServiceProvider); withRouting's own shortcut always registers it with the bare
    // 'web' group and no way to add middleware to it.
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['middleware' => ['web', 'auth', 'throttle:notifications']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            \App\Http\Middleware\EnsureAccountIsActive::class,
            \App\Http\Middleware\EnsurePasswordIsChanged::class,
            \App\Http\Middleware\AttachMonitoringContext::class,
            \App\Http\Middleware\SetLocale::class,
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);
        $middleware->web(prepend: [\App\Http\Middleware\EnforceHttps::class]);

        // Render (like most PaaS hosts) terminates HTTPS at its edge and
        // forwards to this container over plain HTTP. Without trusting
        // that proxy's X-Forwarded-Proto header, Laravel generates every
        // asset/URL as http:// regardless of APP_URL, which browsers
        // then block as mixed content on the https:// page.
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR |
                Request::HEADER_X_FORWARDED_HOST |
                Request::HEADER_X_FORWARDED_PORT |
                Request::HEADER_X_FORWARDED_PROTO,
        );

        $middleware->alias([
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'privacy' => \App\Http\Middleware\PrivacyHeaders::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A refused delete (the record has history depending on it) is an ordinary
        // "no, and here is why" for the person, not a server error.
        $exceptions->render(function (\App\Exceptions\LockedRecordException $e, Request $request) {
            return back()->withErrors(['locked' => $e->getMessage()]);
        });
        $exceptions->render(function (\App\Exceptions\DeletionBlockedException $e, Request $request) {
            return back()->withErrors(['delete' => $e->getMessage()]);
        });

        // Reports every exception that reaches here to Sentry (subject to config/sentry.php's
        // ignore_exceptions and the SentryScrubber before_send) -- a safe no-op when no DSN is
        // configured (local/testing always have none; see config/sentry.php).
        \Sentry\Laravel\Integration::handles($exceptions);

        // Every rate-limit violation in the app (whichever named limiter tripped -- see
        // RateLimitServiceProvider) ends up here as a 429. Laravel's default exception handler never calls
        // report() for this exception (HttpException -- which TooManyRequestsHttpException extends -- is in
        // the framework's own internal "don't report" list, since an ordinary 403/404/429 isn't normally an
        // application error worth reporting to Sentry/logs), so the logging has to happen here in respond()
        // instead, which runs for every response regardless of that list -- and deliberately OUTSIDE the
        // app.debug-guarded block below, so a violation is still logged in local/staging even with debug on.
        // One SecurityEvent per route+identity, debounced to at most one write every 5 minutes no matter how
        // fast the offender keeps retrying, specifically so the logging itself can never become the
        // resource-exhaustion attack (see RATE_LIMITING_AND_ABUSE_PROTECTION.md). Never logs the request body, so no
        // password/token typed into a throttled form ever reaches this (or any) log.
        $exceptions->respond(function (Response $response, \Throwable $e, Request $request) {
            if ($response->getStatusCode() === 429) {
                \App\Support\RateLimitLog::record($request);
            }

            // Production-readiness audit finding: this app is 100% Inertia (no server-rendered Blade
            // screens except PDFs/print), but nothing here ever rendered an Inertia-aware error page --
            // a 403/404/419/500/503 fell through to Laravel's own bare default error view instead of
            // the app's own plain-language, low-literacy-friendly design. Skipped entirely when
            // APP_DEBUG is on, so local development still sees the real stack trace.
            if (! config('app.debug') && in_array($response->getStatusCode(), [403, 404, 419, 429, 500, 503], true)) {
                $friendly = Inertia::render('Error', ['status' => $response->getStatusCode()])
                    ->toResponse($request)
                    ->setStatusCode($response->getStatusCode());

                // A 429's Retry-After / X-RateLimit-* headers are real, useful information for whatever is on
                // the other end (a human's browser retry, or a well-behaved script) -- carry them onto the
                // friendly page instead of silently dropping them when swapping in this response.
                foreach (['Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining'] as $header) {
                    if ($response->headers->has($header)) {
                        $friendly->headers->set($header, $response->headers->get($header));
                    }
                }

                return $friendly;
            }

            return $response;
        });
    })->create();
