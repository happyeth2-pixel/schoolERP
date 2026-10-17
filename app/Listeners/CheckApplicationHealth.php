<?php

namespace App\Listeners;

use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Makes `/up` actually mean something for "is the database available", not just "did PHP boot".
 * Laravel dispatches DiagnosingHealth on every hit to /up; throwing here makes that request
 * return HTTP 500 (and, since bootstrap/app.php wires Sentry\Laravel\Integration::handles(),
 * reports the failure to Sentry too) instead of the default "yes" that ignores the database
 * entirely. An external uptime monitor pointed at /up is what actually turns this into an alert
 * -- see MONITORING.md.
 */
class CheckApplicationHealth
{
    public function handle(DiagnosingHealth $event): void
    {
        $this->checkDatabase();
        $this->checkCache();
    }

    private function checkDatabase(): void
    {
        try {
            DB::connection()->select('select 1');
        } catch (\Throwable $e) {
            throw new RuntimeException('Database is not reachable: '.$e->getMessage(), previous: $e);
        }
    }

    private function checkCache(): void
    {
        $key = '__health_check__';

        try {
            Cache::put($key, '1', 10);
            if (Cache::get($key) !== '1') {
                throw new RuntimeException('Cache store did not return the value that was just written.');
            }
        } catch (RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new RuntimeException('Cache store is not reachable: '.$e->getMessage(), previous: $e);
        }
    }
}
