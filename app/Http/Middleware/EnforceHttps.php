<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * When app.force_https is on (default in production): plain-HTTP requests are
 * redirected to HTTPS and every HTTPS response carries an HSTS header, so
 * browsers refuse to talk to the site over HTTP again. Proxy headers are
 * already trusted (bootstrap/app.php), so $request->secure() is correct behind
 * Render's TLS edge. The /up health check is not a web-group route, so it is
 * never redirected.
 */
class EnforceHttps
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('app.force_https')) {
            return $next($request);
        }

        if (! $request->secure()) {
            return redirect()->secure($request->getRequestUri(), 301);
        }

        $response = $next($request);
        $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');

        return $response;
    }
}
