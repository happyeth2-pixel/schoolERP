<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * For pages that show a person's identity from a link/QR: never cached (by the
 * browser, a proxy or CDN), never indexed by search engines, and the URL --
 * which contains the token -- is never sent on as a Referer.
 */
class PrivacyHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('Cache-Control', 'no-store, private, max-age=0');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
