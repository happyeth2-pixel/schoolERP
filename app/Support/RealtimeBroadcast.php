<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * A short-lived circuit breaker in front of the real-time push (Reverb). Without this, a down Reverb
 * would make every notification created in one bulk operation (a whole grade's bills, a section-wide
 * announcement, a term's worth of attendance) separately rediscover the SAME outage -- each one paying
 * its own connection-timeout cost (see config/broadcasting.php's client_options), which adds up fast
 * across dozens of notifications in a single request even at just one or two seconds each.
 *
 * One failure marks Reverb down for a short window; everything created during that window skips the
 * network call entirely and goes straight to the database only (the one thing that actually matters --
 * see AppServiceProvider::boot(), where the row is always written first regardless of this). After the
 * window, the next notification tries again on its own, so a Reverb that comes back up is noticed
 * automatically -- never a flag a person has to remember to clear.
 */
class RealtimeBroadcast
{
    private const DOWN_KEY = 'realtime_broadcast:down';
    private const DOWN_FOR_SECONDS = 20;

    public static function isDown(): bool
    {
        return (bool) Cache::get(self::DOWN_KEY, false);
    }

    public static function markDown(): void
    {
        Cache::put(self::DOWN_KEY, true, self::DOWN_FOR_SECONDS);
    }
}
