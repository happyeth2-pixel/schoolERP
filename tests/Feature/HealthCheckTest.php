<?php

namespace Tests\Feature;

use App\Listeners\CheckApplicationHealth;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * /up is what an external uptime monitor watches (see MONITORING.md); it must actually reflect
 * database health, not just "PHP booted". Laravel's own contract for /up (a listener on
 * DiagnosingHealth that throws => /up returns 500) is framework behaviour, not this app's --
 * what these tests prove is that App\Listeners\CheckApplicationHealth actually throws when it
 * should and stays quiet when the database is genuinely fine.
 */
class HealthCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_up_succeeds_when_the_database_is_reachable(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_the_listener_passes_silently_when_the_database_and_cache_are_fine(): void
    {
        (new CheckApplicationHealth)->handle(new DiagnosingHealth);
        $this->assertTrue(true, 'no exception means the health check passed, which is the point');
    }

    public function test_the_listener_throws_when_the_database_is_not_reachable(): void
    {
        // Points the DEFAULT connection at a brand-new, never-before-used connection name that is
        // broken on purpose, rather than touching the real "sqlite" connection RefreshDatabase (and
        // every other test in this run, since :memory: only survives on one live PDO instance) relies
        // on -- so nothing here needs cleanup beyond restoring the one config key.
        $originalDefault = config('database.default');
        config([
            'database.default' => 'intentionally_broken_for_this_test',
            'database.connections.intentionally_broken_for_this_test' => ['driver' => 'sqlite', 'database' => '/nonexistent/path/does-not-exist.sqlite'],
        ]);

        try {
            (new CheckApplicationHealth)->handle(new DiagnosingHealth);
            $this->fail('expected the health check to throw when the database is unreachable');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Database is not reachable', $e->getMessage());
        } finally {
            config(['database.default' => $originalDefault]);
        }
    }
}
