<?php

namespace Tests\Feature;

use App\Models\SecurityEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

/**
 * The centralized rate-limiting system (config/rate_limits.php, App\Providers\RateLimitServiceProvider) --
 * everything EXCEPT sign-in itself, which has its own deeper, separately-tested system (see
 * tests/Feature/Auth/LoginThrottleTest.php and App\Security\LoginThrottle).
 *
 * Every test here turns a category's real (generous) threshold down to something small first
 * (config(['rate_limits.X.per_minute' => N])) so it can actually be exceeded in a handful of requests rather
 * than hammering the production numbers hundreds of times -- the THRESHOLD values themselves are plain config,
 * not logic, and are covered by their own documented rationale in rate_limits.php and the production-readiness
 * report, not by assertions here.
 */
class RateLimitingTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();   // the array-cache-backed limiter state must not bleed between tests
        $this->buildSchool();
    }

    // ------------------------------------------------------------------ normal use is never affected

    public function test_ordinary_use_well_under_the_limit_is_never_throttled(): void
    {
        config(['rate_limits.search.per_minute' => 10]);
        $user = $this->makeUser('branch_admin', $this->branchA->id, ['manage-students']);

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($user)->get('/students?q=test')->assertOk();
        }
    }

    // ------------------------------------------------------------------ exceeding a limit -> 429

    public function test_exceeding_the_configured_limit_returns_429(): void
    {
        config(['rate_limits.search.per_minute' => 3]);
        $user = $this->makeUser('branch_admin', $this->branchA->id, ['manage-students']);

        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($user)->get('/students?q=test')->assertOk();
        }
        $this->actingAs($user)->get('/students?q=test')->assertStatus(429);
    }

    public function test_the_429_response_carries_retry_after_and_rate_limit_headers(): void
    {
        config(['rate_limits.search.per_minute' => 1]);
        $user = $this->makeUser('branch_admin', $this->branchA->id, ['manage-students']);

        $this->actingAs($user)->get('/students?q=test')->assertOk();
        $blocked = $this->actingAs($user)->get('/students?q=test')->assertStatus(429);

        $blocked->assertHeader('Retry-After');
        $this->assertTrue((int) $blocked->headers->get('Retry-After') > 0);
        $blocked->assertHeader('X-RateLimit-Limit');
    }

    public function test_the_429_page_itself_is_the_apps_own_friendly_error_page(): void
    {
        config(['rate_limits.search.per_minute' => 1, 'app.debug' => false]);
        $user = $this->makeUser('branch_admin', $this->branchA->id, ['manage-students']);

        $this->actingAs($user)->get('/students?q=test')->assertOk();
        $blocked = $this->actingAs($user)->get('/students?q=test')->assertStatus(429);

        // the Inertia-wrapped Error page, not Laravel's bare default 429 view
        $this->assertSame('Error', $blocked->original->getData()['page']['component']);
        $this->assertSame(429, $blocked->original->getData()['page']['props']['status']);
        // and the Retry-After header survived being wrapped into that friendly page
        $blocked->assertHeader('Retry-After');
    }

    // ------------------------------------------------------------------ identity: per-user, not just per-IP

    public function test_two_different_users_sharing_one_ip_each_get_their_own_budget(): void
    {
        config(['rate_limits.search.per_minute' => 2]);
        $receptionist = $this->makeUser('branch_admin', $this->branchA->id, ['manage-students']);
        $accountant = $this->makeUser('branch_admin', $this->branchA->id, ['manage-students']);

        // both "connect" from the same address (every request in-process shares 127.0.0.1 anyway, which is
        // exactly the point: nothing here keys on IP for an authenticated request)
        $this->actingAs($receptionist)->get('/students?q=a')->assertOk();
        $this->actingAs($receptionist)->get('/students?q=b')->assertOk();
        $this->actingAs($receptionist)->get('/students?q=c')->assertStatus(429, 'the receptionist is now over their own budget');

        // the accountant, same IP, has used none of their own budget yet
        $this->actingAs($accountant)->get('/students?q=a')->assertOk();
        $this->actingAs($accountant)->get('/students?q=b')->assertOk();
    }

    public function test_one_account_cannot_dodge_its_own_limit_by_appearing_from_a_different_ip(): void
    {
        config(['rate_limits.search.per_minute' => 2]);
        $user = $this->makeUser('branch_admin', $this->branchA->id, ['manage-students']);

        $this->actingAs($user)->get('/students?q=a', ['REMOTE_ADDR' => '10.0.0.1'])->assertOk();
        $this->actingAs($user)->get('/students?q=b', ['REMOTE_ADDR' => '10.0.0.2'])->assertOk();
        // same account, a THIRD apparent address -- still their own budget, because the key is the user id
        $this->actingAs($user)->get('/students?q=c', ['REMOTE_ADDR' => '10.0.0.3'])->assertStatus(429);
    }

    public function test_anonymous_requests_to_a_public_route_are_keyed_by_ip(): void
    {
        config(['rate_limits.password_reset.per_minute' => 2]);

        $this->post('/forgot-password', ['email' => 'a@example.test'])->assertSessionHasErrors();
        $this->post('/forgot-password', ['email' => 'b@example.test'])->assertSessionHasErrors();
        $this->post('/forgot-password', ['email' => 'c@example.test'])->assertStatus(429);
    }

    // ------------------------------------------------------------------ password-reset abuse (the real gap this closed)

    public function test_password_reset_requests_were_previously_completely_unthrottled_now_are_not(): void
    {
        config(['rate_limits.password_reset.per_minute' => 5, 'rate_limits.password_reset.per_hour' => 5]);

        for ($i = 0; $i < 5; $i++) {
            $this->post('/forgot-password', ['email' => "enum{$i}@example.test"]);
        }
        // the 6th -- a different address every time, which is exactly the account-enumeration/mail-flood shape --
        // is refused outright rather than silently accepted forever
        $this->post('/forgot-password', ['email' => 'enum-overflow@example.test'])->assertStatus(429);
    }

    public function test_the_reset_password_confirm_form_is_throttled_too(): void
    {
        config(['rate_limits.password_reset.per_minute' => 2]);

        $this->post('/reset-password', ['token' => 'x', 'email' => 'a@example.test', 'password' => 'whatever', 'password_confirmation' => 'whatever']);
        $this->post('/reset-password', ['token' => 'x', 'email' => 'a@example.test', 'password' => 'whatever', 'password_confirmation' => 'whatever']);
        $this->post('/reset-password', ['token' => 'x', 'email' => 'a@example.test', 'password' => 'whatever', 'password_confirmation' => 'whatever'])
            ->assertStatus(429);
    }

    // ------------------------------------------------------------------ export / upload / bulk abuse

    public function test_export_abuse_is_throttled_tighter_than_ordinary_browsing(): void
    {
        config(['rate_limits.export.per_minute' => 2]);
        $owner = $this->owner;

        $this->actingAs($owner)->get('/students/export')->assertOk();
        $this->actingAs($owner)->get('/students/export')->assertOk();
        $this->actingAs($owner)->get('/students/export')->assertStatus(429);

        // but an ORDINARY page for the same user, same minute, is unaffected -- a different limiter entirely
        $this->actingAs($owner)->get('/dashboard')->assertOk();
    }

    public function test_an_ip_backstop_catches_several_different_accounts_hammering_exports_from_one_address(): void
    {
        config(['rate_limits.export.per_minute' => 50, 'rate_limits.export.ip_per_minute' => 2]);
        $a = $this->makeUser('branch_admin', $this->branchA->id, ['manage-students']);
        $b = $this->makeUser('branch_admin', $this->branchA->id, ['manage-students']);
        $c = $this->makeUser('branch_admin', $this->branchA->id, ['manage-students']);

        $this->actingAs($a)->get('/students/export')->assertOk();
        $this->actingAs($b)->get('/students/export')->assertOk();
        // each account is still well under ITS OWN 50/min budget, but the shared-IP backstop (2/min) now trips
        $this->actingAs($c)->get('/students/export')->assertStatus(429);
    }

    public function test_upload_abuse_is_throttled(): void
    {
        config(['rate_limits.upload.per_minute' => 2]);
        $owner = $this->owner;

        $file = \Illuminate\Http\UploadedFile::fake()->image('photo.jpg', 100, 100)->size(50);
        $this->actingAs($owner)->post('/profile/photo', ['photo' => $file]);
        $this->actingAs($owner)->post('/profile/photo', ['photo' => $file]);
        $this->actingAs($owner)->post('/profile/photo', ['photo' => $file])->assertStatus(429);
    }

    public function test_bulk_generation_is_throttled(): void
    {
        config(['rate_limits.bulk.per_minute' => 2]);
        $owner = $this->owner;

        $this->actingAs($owner)->get('/invoices/generate/preview?branch_id='.$this->branchA->id.'&grade_id='.$this->grade->id);
        $this->actingAs($owner)->get('/invoices/generate/preview?branch_id='.$this->branchA->id.'&grade_id='.$this->grade->id);
        $this->actingAs($owner)->get('/invoices/generate/preview?branch_id='.$this->branchA->id.'&grade_id='.$this->grade->id)
            ->assertStatus(429);
    }

    // ------------------------------------------------------------------ reports

    public function test_financial_reports_share_one_reports_budget_across_all_eight(): void
    {
        config(['rate_limits.reports.per_minute' => 3]);
        $owner = $this->owner;

        $this->actingAs($owner)->get('/accounting/reports/trial-balance')->assertOk();
        $this->actingAs($owner)->get('/accounting/reports/balance-sheet')->assertOk();
        $this->actingAs($owner)->get('/accounting/reports/cash-summary')->assertOk();
        // the 4th report in the minute, even a different one again, is the one that trips it
        $this->actingAs($owner)->get('/accounting/reports/ledger')->assertStatus(429);
    }

    // ------------------------------------------------------------------ route coverage (no silent regressions)

    public static function protectedRoutes(): array
    {
        return [
            'students quick find' => ['get', '/students', 'search'],
            'staff export' => ['get', '/staff/export', 'export'],
            'students export' => ['get', '/students/export', 'export'],
            'invoices export' => ['get', '/invoices/export', 'export'],
            'audit log export' => ['get', '/audit-log/export', 'export'],
            'dashboard' => ['get', '/dashboard', 'dashboard'],
            'notifications' => ['get', '/notifications', 'notifications'],
            'parents search' => ['get', '/parents/search', 'search'],
        ];
    }

    /** @dataProvider protectedRoutes */
    public function test_every_named_heavy_route_still_carries_its_throttle_middleware(string $method, string $uri, string $expectedLimiter): void
    {
        $route = collect(\Illuminate\Support\Facades\Route::getRoutes())->first(fn ($r) => $r->uri() === ltrim($uri, '/') && in_array(strtoupper($method), $r->methods()));

        $this->assertNotNull($route, "no route registered for {$method} {$uri}");
        $this->assertContains("throttle:{$expectedLimiter}", $route->middleware(), "{$method} {$uri} is missing throttle:{$expectedLimiter}");
    }

    // ------------------------------------------------------------------ bypass attempts

    public function test_extra_or_different_query_parameters_do_not_create_a_fresh_budget(): void
    {
        config(['rate_limits.search.per_minute' => 2]);
        $user = $this->makeUser('branch_admin', $this->branchA->id, ['manage-students']);

        $this->actingAs($user)->get('/students?q=one')->assertOk();
        $this->actingAs($user)->get('/students?q=two&page=1')->assertOk();
        // a third request, differently-shaped query string again, still counts against the same per-user budget
        $this->actingAs($user)->get('/students?q=three&page=2&extra=1')->assertStatus(429);
    }

    public function test_head_requests_count_against_the_same_budget_as_get(): void
    {
        config(['rate_limits.search.per_minute' => 2]);
        $user = $this->makeUser('branch_admin', $this->branchA->id, ['manage-students']);

        $this->actingAs($user)->get('/students?q=one')->assertOk();
        $this->actingAs($user)->call('HEAD', '/students', ['q' => 'two'])->assertOk();
        $this->actingAs($user)->get('/students?q=three')->assertStatus(429);
    }

    // ------------------------------------------------------------------ logging: once per violation window, never a flood

    public function test_a_rate_limit_violation_is_logged_once_and_debounced_against_repeats(): void
    {
        config(['rate_limits.search.per_minute' => 1]);
        $user = $this->makeUser('branch_admin', $this->branchA->id, ['manage-students']);

        $this->actingAs($user)->get('/students?q=one')->assertOk();
        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($user)->get('/students?q=repeat'.$i)->assertStatus(429);
        }

        $this->assertSame(1, SecurityEvent::where('event', 'rate_limited')->where('user_id', $user->id)->count(), 'five retries in a row must produce exactly one log row, not five');
    }

    public function test_the_logged_event_never_contains_a_password_or_search_term(): void
    {
        config(['rate_limits.password_reset.per_minute' => 1]);

        $this->post('/forgot-password', ['email' => 'first@example.test']);
        $this->post('/forgot-password', ['email' => 'super-secret-address@example.test'])->assertStatus(429);

        $event = SecurityEvent::where('event', 'rate_limited')->first();
        $this->assertNotNull($event);
        $body = json_encode($event->toArray());
        $this->assertStringNotContainsString('super-secret-address', $body);
    }

    public function test_the_apps_default_cache_store_is_a_shared_one_not_per_process_memory(): void
    {
        // RateLimiter (and therefore every limiter above) is backed by whatever Laravel's default cache store
        // is -- nothing in this feature chooses its own store. This asserts what config/cache.php falls back to
        // when CACHE_STORE isn't set at all (reading the config SOURCE, not the live value -- this process's own
        // CACHE_STORE is deliberately 'array' for the test suite, see phpunit.xml, which is a test-environment
        // choice and not what should ever ship as the undeclared default): 'database', a store every instance of
        // the app shares, never 'array' (this one process's own memory, gone the instant it restarts) or 'file'
        // (this one container's own disk) -- either of which would make limits inconsistent the moment the app
        // ever runs on more than one instance.
        $source = file_get_contents(config_path('cache.php'));
        $this->assertMatchesRegularExpression(
            "/'default'\s*=>\s*env\('CACHE_STORE',\s*'database'\)/",
            $source,
            'config/cache.php must default to a shared store (database) when CACHE_STORE is unset'
        );
    }
}
