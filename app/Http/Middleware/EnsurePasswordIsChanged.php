<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * An account whose password is a temporary one (just created, or reset by an administrator) can do exactly one
 * thing after signing in: choose a new password (or sign out). Everything else redirects there, so the temporary
 * password cannot be used to work in the system.
 */
class EnsurePasswordIsChanged
{
    private const ALLOWED = ['password.required', 'password.required.update', 'logout'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->must_change_password && ! in_array($request->route()?->getName(), self::ALLOWED, true)) {
            return redirect()->route('password.required');
        }

        return $next($request);
    }
}
