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

        // Production-readiness audit finding: this app is 100% Inertia (no server-rendered Blade
        // screens except PDFs/print), but nothing here ever rendered an Inertia-aware error page --
        // a 403/404/419/500/503 fell through to Laravel's own bare default error view instead of
        // the app's own plain-language, low-literacy-friendly design. Skipped entirely when
        // APP_DEBUG is on, so local development still sees the real stack trace.
        $exceptions->respond(function (Response $response, \Throwable $e, Request $request) {
            if (! config('app.debug') && in_array($response->getStatusCode(), [403, 404, 419, 429, 500, 503], true)) {
                return Inertia::render('Error', ['status' => $response->getStatusCode()])
                    ->toResponse($request)
                    ->setStatusCode($response->getStatusCode());
            }

            return $response;
        });
    })->create();
