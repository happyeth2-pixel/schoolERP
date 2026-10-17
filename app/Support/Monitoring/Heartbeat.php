<?php

namespace App\Support\Monitoring;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * "Backup success/failure" monitoring, without inventing a whole notifications system: a plain
 * HTTP GET to a URL a monitoring service gave you, after every backup:run/backup:verify. If the
 * ping stops arriving on schedule, THAT service raises the alert -- this is the standard
 * "dead man's switch" pattern and works with any provider that hands out a ping URL
 * (Healthchecks.io, Cronitor, Better Uptime heartbeats, ...): nothing here is provider-specific.
 *
 * A failed ping never fails the command it's called from -- monitoring the backup must never be
 * what breaks the backup.
 */
class Heartbeat
{
    /**
     * @param  string|null  $url  the base ping URL for this check (e.g. https://hc-ping.com/<uuid>); a no-op if null/blank
     * @param  bool  $success  whether the thing being reported on actually succeeded
     */
    public static function ping(?string $url, bool $success): void
    {
        if (blank($url)) {
            return;
        }

        $target = $success ? $url : self::failureUrl($url);

        try {
            Http::timeout(10)->get($target);
        } catch (\Throwable $e) {
            // Never let a monitoring ping's own failure (network blip, DNS, provider outage) fail
            // the backup command that's reporting in -- just note it so it isn't silently invisible.
            Log::warning('Heartbeat ping failed', ['url' => $target, 'error' => $e->getMessage()]);
        }
    }

    /** Healthchecks.io's convention (a "/fail" suffix) for actively signalling failure rather than just staying silent; overridable for a provider with a different convention. */
    private static function failureUrl(string $url): string
    {
        $suffix = config('backup.heartbeat.fail_suffix', '/fail');

        return rtrim($url, '/').$suffix;
    }
}
