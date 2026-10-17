<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * A deactivated account (status "inactive") cannot use the system --
 * including one that is already signed in. Accounts are deactivated, never
 * deleted, so everything they did stays attributed to them.
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ($user->status === 'inactive' || $user->hasRole('student'))) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['phone' => 'This account has been deactivated. Please contact the school administrator.']);
        }

        return $next($request);
    }
}
