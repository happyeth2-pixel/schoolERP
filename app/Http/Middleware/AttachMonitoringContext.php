<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Sentry\State\Scope;
use Sentry\UserDataBag;
use Symfony\Component\HttpFoundation\Response;

/**
 * Attaches just enough to correlate an error report with "who it happened to" for support
 * purposes -- WITHOUT their name, email or phone: a stable pseudonymous id (a one-way hash of
 * the user id, salted with APP_KEY, so it can't be reversed to an account without database
 * access, but the SAME person always hashes to the SAME value, so "this keeps happening to one
 * account" is still visible), their role, and school/branch id.
 *
 * `Sentry::configureScope()` (and the underlying `\Sentry\configureScope()`) are safe no-ops
 * when no DSN is configured, so this runs unconditionally rather than checking first.
 */
class AttachMonitoringContext
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($user = $request->user()) {
            \Sentry\configureScope(function (Scope $scope) use ($user) {
                $scope->setUser(new UserDataBag(self::pseudonym($user->id)));
                $scope->setTags(array_filter([
                    'role' => $user->getRoleNames()->first(),
                    'school_id' => $user->school_id ? (string) $user->school_id : null,
                    'branch_id' => $user->branch_id ? (string) $user->branch_id : null,
                ]));
            });
        }

        return $next($request);
    }

    /** A stable, non-reversible stand-in for a user id -- the same account always yields the same value, but it can't be turned back into that id without the database. */
    public static function pseudonym(int $userId): string
    {
        return 'u_'.substr(hash_hmac('sha256', (string) $userId, config('app.key')), 0, 12);
    }
}
