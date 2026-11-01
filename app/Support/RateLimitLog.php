<?php

namespace App\Support;

use App\Models\SecurityEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Records a rate-limit violation (any named limiter in RateLimitServiceProvider, or the login/id-verify/
 * invitation limiters that already existed) to the existing SecurityEvent log -- but debounced, so hammering a
 * throttled endpoint can never itself become a way to fill the database with rows. At most one SecurityEvent
 * row per (route, identity) pair every DEBOUNCE_SECONDS, no matter how many times the offender keeps retrying
 * in between; everything in between is still correctly blocked with a 429, just not individually logged.
 *
 * Never receives or logs the request body, so nothing a person typed into a throttled form (a password, a
 * search term that happened to look like one) ever reaches this or any other log.
 */
class RateLimitLog
{
    private const DEBOUNCE_SECONDS = 300;

    public static function record(Request $request): void
    {
        $route = $request->route()?->getName() ?? $request->path();
        $user = $request->user();
        $identity = $user ? "user:{$user->id}" : 'ip:'.$request->ip();

        $debounceKey = "ratelimit-logged:{$route}:{$identity}";
        if (! Cache::add($debounceKey, true, self::DEBOUNCE_SECONDS)) {
            return;
        }

        try {
            // SecurityEvent::log() already captures the request's IP in its own ip_address column regardless
            // of $identifier, so there's nothing to pass there for an anonymous (no-user) violation.
            SecurityEvent::log('rate_limited', $user, null, null, [
                'route' => $route,
                'method' => $request->method(),
            ]);
        } catch (\Throwable $e) {
            // Logging the violation must never be the thing that turns a 429 into a 500 for the person
            // retrying too fast -- if the log write itself fails (e.g. the database is already under the
            // load that triggered the throttle in the first place), note it once in the application log and
            // let the 429 response go out regardless.
            Log::warning('RateLimitLog: failed to record a rate-limit violation', ['error' => $e->getMessage()]);
        }
    }
}
