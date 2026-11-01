<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Security\LoginThrottle;
use Carbon\Carbon;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

/**
 * Layered sign-in abuse protection: per account (short growing waits, then a temporary lock), per known device, per
 * network address (watched, never locked) and burst. The point is as much what does NOT happen: a school of people
 * sharing one address is never shut out, and nothing tells an attacker which accounts exist.
 */
class LoginThrottleTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    private const PASSWORD = 'secret-pass-1';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 09:00:00');
        Cache::flush();
        config(['login_security.burst.attempts' => 1000]);   // the burst cap has its own test
        $this->buildSchool();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function account(string $phone, string $role = 'teacher'): User
    {
        $u = User::create(['school_id' => $this->school->id, 'branch_id' => $this->branchA->id, 'name' => 'Acct '.$phone, 'phone' => $phone, 'login_phone' => $phone, 'password' => self::PASSWORD, 'status' => 'active']);
        $u->assignTier($role);

        return $u;
    }

    private function attempt(string $phone, string $password = 'wrong', string $ip = '10.0.0.1', array $cookies = []): string
    {
        $this->post('/logout');
        $response = $this->withServerVariables(['REMOTE_ADDR' => $ip])->withCookies($cookies)->post('/login', ['phone' => $phone, 'password' => $password]);

        return (string) ($response->baseResponse->getSession()->get('errors')?->first('phone') ?? 'OK');
    }

    private function failTimes(string $phone, int $times, string $ip = '10.0.0.1'): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->attempt($phone, 'wrong', $ip);
            Carbon::setTestNow(now()->addSeconds(1000));   // long enough that no wait is running between these tries
        }
    }

    private function passTime(int $seconds): void
    {
        Carbon::setTestNow(now()->addSeconds($seconds));
    }

    private const GENERIC = 'The phone number or password is incorrect. Check both and try again.';

    // ------------------------------------------------------------------ the account layer

    public function test_a_few_mistakes_are_free_then_a_short_wait_begins(): void
    {
        $this->account('0911223344');

        foreach ([1, 2, 3] as $i) {
            $this->assertSame(self::GENERIC, $this->attempt('0911223344'), "try {$i} is an ordinary wrong-password answer");
        }

        $blocked = $this->attempt('0911223344', self::PASSWORD);   // even the right password is not checked during the wait
        $this->assertStringContainsString('Too many unsuccessful login attempts', $blocked);
        $this->assertStringContainsString('10 seconds', $blocked);
        $this->assertGuest();
    }

    public function test_the_wait_ends_and_the_right_password_then_works(): void
    {
        $user = $this->account('0911223344');
        for ($i = 0; $i < 3; $i++) {
            $this->attempt('0911223344');
        }

        $this->passTime(5);
        $this->assertStringContainsString('5 seconds', $this->attempt('0911223344', self::PASSWORD), 'the remaining time is shown, counting down');
        $this->passTime(6);

        $this->assertSame('OK', $this->attempt('0911223344', self::PASSWORD));
        $this->assertAuthenticatedAs($user);
    }

    public function test_waits_grow_with_each_further_failure(): void
    {
        $this->account('0911223344');
        for ($i = 0; $i < 3; $i++) {
            $this->attempt('0911223344');
        }

        $expected = [10, 30, 60, 300, 900];
        foreach ($expected as $n => $seconds) {
            $this->passTime($seconds + 1);
            $this->assertSame(self::GENERIC, $this->attempt('0911223344'), 'a wrong try after the wait is an ordinary failure again');
            $next = $expected[$n + 1] ?? null;
            if ($next) {
                $this->assertStringContainsString($this->human($next), $this->attempt('0911223344', self::PASSWORD), 'and it starts the next, longer wait');
            }
        }
    }

    private function human(int $seconds): string
    {
        return $seconds < 90 ? "{$seconds} seconds" : ceil($seconds / 60).' minutes';
    }

    public function test_many_failures_lock_the_account_for_a_while_and_the_lock_expires(): void
    {
        $user = $this->account('0911223344');
        $this->failTimes('0911223344', 9);   // 9 failures, each after the previous wait

        $this->assertSame(self::GENERIC, $this->attempt('0911223344'));   // the 10th
        $locked = $this->attempt('0911223344', self::PASSWORD);
        $this->assertStringContainsString('30 minutes', $locked);
        $this->assertGuest();

        $this->passTime(29 * 60);
        $this->assertStringContainsString('Too many', $this->attempt('0911223344', self::PASSWORD), 'still locked a minute before the end');
        $this->passTime(2 * 60);

        $this->assertSame('OK', $this->attempt('0911223344', self::PASSWORD));
        $this->assertAuthenticatedAs($user);
        $this->assertTrue(SecurityEvent::where('event', 'account_locked')->where('user_id', $user->id)->exists());
    }

    public function test_a_quiet_spell_forgets_the_failures(): void
    {
        $this->account('0911223344');
        $this->failTimes('0911223344', 3);

        $this->passTime(2 * 3600);
        $this->assertSame(self::GENERIC, $this->attempt('0911223344'));
        $this->assertSame(self::GENERIC, $this->attempt('0911223344'));
        $this->assertSame(self::GENERIC, $this->attempt('0911223344'), 'the free tries are back');
    }

    public function test_the_counter_belongs_to_the_number_however_it_is_typed_and_from_wherever(): void
    {
        $this->account('0911223344');
        foreach (['0911223344', '+251911223344', '911223344'] as $i => $typed) {
            $this->attempt($typed, 'wrong', '10.0.0.'.($i + 1));   // three different addresses too
        }

        $this->assertStringContainsString('Too many', $this->attempt('0911 22 33 44', self::PASSWORD, '10.0.0.9'));
    }

    public function test_one_persons_mistakes_do_not_slow_another_person_on_the_same_address(): void
    {
        $a = $this->account('0911223344');
        $b = $this->account('0922334455', 'accountant');
        for ($i = 0; $i < 6; $i++) {
            $this->attempt('0911223344', 'wrong', '10.0.0.1');
        }

        $this->assertStringContainsString('Too many', $this->attempt('0911223344', self::PASSWORD, '10.0.0.1'));
        $this->assertSame('OK', $this->attempt('0922334455', self::PASSWORD, '10.0.0.1'));
        $this->assertAuthenticatedAs($b);
    }

    public function test_an_unknown_number_is_throttled_exactly_like_a_real_one(): void
    {
        $this->account('0911223344');
        $real = $unknown = [];
        for ($i = 0; $i < 5; $i++) {
            $real[] = $this->attempt('0911223344');
            $unknown[] = $this->attempt('0977000000');
            $this->passTime(2);
        }

        $this->assertSame($real, $unknown, 'nothing in the answers reveals which number is an account');
        $this->assertSame($this->attempt('0911223344'), $this->attempt('0977000000'));
    }

    public function test_the_messages_never_reveal_thresholds_or_why(): void
    {
        $this->account('0911223344');
        $this->failTimes('0911223344', 2);
        $msg = $this->attempt('0911223344');
        $blocked = $this->attempt('0911223344', self::PASSWORD);

        foreach ([$msg, $blocked] as $m) {
            $this->assertStringNotContainsStringIgnoringCase('locked', $m);
            $this->assertStringNotContainsStringIgnoringCase('exist', $m);
            $this->assertStringNotContainsString('remaining', strtolower($m));
            $this->assertDoesNotMatchRegularExpression('/\b(3|5|10) (attempts|tries)\b/i', $m);
        }
    }

    // ------------------------------------------------------------------ the address layer

    public function test_a_busy_school_behind_one_address_is_never_slowed_by_ordinary_mistakes(): void
    {
        $users = [];
        foreach (range(1, 6) as $n) {
            $users[$n] = $this->account('091100000'.$n);
        }
        foreach ($users as $n => $u) {
            $this->attempt('091100000'.$n, 'wrong', '196.188.1.1');   // six people each mistype once
            $this->attempt('091100000'.$n, 'wrong', '196.188.1.1');   // and again
        }

        $newcomer = $this->account('0922000000', 'accountant');
        $this->assertSame('OK', $this->attempt('0922000000', self::PASSWORD, '196.188.1.1'), 'the seventh person signs in at once');
        $this->assertAuthenticatedAs($newcomer);
        $this->assertFalse(SecurityEvent::where('event', 'ip_pressure')->exists());
        foreach ($users as $n => $u) {
            $this->passTime(1000);
            $this->assertSame('OK', $this->attempt('091100000'.$n, self::PASSWORD, '196.188.1.1'), 'and each of them can sign in afterwards');
        }
    }

    public function test_an_address_that_tries_many_different_accounts_is_flagged_and_unknown_browsers_are_slowed_not_locked(): void
    {
        foreach (range(1, 8) as $n) {
            $this->attempt('09330000'.str_pad((string) $n, 2, '0', STR_PAD_LEFT), 'guess', '203.0.113.9');   // eight different numbers from one address
        }

        $this->assertTrue(SecurityEvent::where('event', 'ip_pressure')->where('ip_address', '203.0.113.9')->exists());
        $victim = $this->account('0944000000');

        $slowed = $this->attempt('0944000000', self::PASSWORD, '203.0.113.9');   // a stranger's browser, straight after the attack
        $this->assertStringContainsString('Too many', $slowed);
        $this->assertStringContainsString('5 seconds', $slowed);

        $this->passTime(6);
        $this->assertSame('OK', $this->attempt('0944000000', self::PASSWORD, '203.0.113.9'), 'a few seconds later the address is not locked');
        $this->assertAuthenticatedAs($victim);
    }

    public function test_lots_of_failures_from_one_address_also_flag_it(): void
    {
        config(['login_security.account.free_attempts' => 1000]);   // isolate the address layer
        for ($i = 0; $i < 25; $i++) {
            $this->attempt('0955000000', 'guess', '203.0.113.10');
        }

        $this->assertTrue(SecurityEvent::where('event', 'ip_pressure')->where('ip_address', '203.0.113.10')->exists());
        $this->assertSame(1, SecurityEvent::where('event', 'ip_pressure')->where('ip_address', '203.0.113.10')->count(), 'flagged once, not once per attempt');
    }

    public function test_another_address_is_not_affected_by_one_that_is_attacking(): void
    {
        foreach (range(1, 8) as $n) {
            $this->attempt('09330000'.str_pad((string) $n, 2, '0', STR_PAD_LEFT), 'guess', '203.0.113.9');
        }
        $u = $this->account('0944000000');

        $this->assertSame('OK', $this->attempt('0944000000', self::PASSWORD, '198.51.100.7'));
        $this->assertAuthenticatedAs($u);
    }

    // ------------------------------------------------------------------ account + address + device together

    public function test_a_device_that_signed_in_before_is_not_locked_out_by_someone_else_guessing(): void
    {
        $owner = $this->account('0911223344');
        $device = json_encode(['d' => 'device-1', 'u' => [$owner->id]]);

        $this->failTimes('0911223344', 10, '203.0.113.50');   // an attacker elsewhere locks the account for strangers
        $this->assertStringContainsString('Too many', $this->attempt('0911223344', self::PASSWORD, '198.51.100.1'), 'a stranger is held back');

        $this->assertSame('OK', $this->attempt('0911223344', self::PASSWORD, '198.51.100.1', ['sd' => $device]), 'the person on their own device is not');
        $this->assertAuthenticatedAs($owner);
    }

    public function test_a_known_device_is_still_throttled_by_its_own_failures(): void
    {
        $owner = $this->account('0911223344');
        $device = json_encode(['d' => 'device-1', 'u' => [$owner->id]]);

        for ($i = 0; $i < 3; $i++) {
            $this->attempt('0911223344', 'wrong', '198.51.100.1', ['sd' => $device]);
        }

        $this->assertStringContainsString('Too many', $this->attempt('0911223344', self::PASSWORD, '198.51.100.1', ['sd' => $device]));
    }

    public function test_a_device_cookie_for_someone_else_gives_no_advantage(): void
    {
        $mine = $this->account('0911223344');
        $other = $this->account('0922334455', 'accountant');
        $this->failTimes('0911223344', 10);

        $this->assertStringContainsString('Too many', $this->attempt('0911223344', self::PASSWORD, '10.0.0.5', ['sd' => json_encode(['d' => 'x', 'u' => [$other->id]])]));
        $this->assertStringContainsString('Too many', $this->attempt('0911223344', self::PASSWORD, '10.0.0.5', ['sd' => 'garbage']));
    }

    public function test_signing_in_remembers_the_device_with_a_protected_cookie(): void
    {
        $user = $this->account('0911223344');

        $response = $this->post('/login', ['phone' => '0911223344', 'password' => self::PASSWORD]);

        $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === 'sd');
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertGreaterThan(now()->addDays(100)->getTimestamp(), $cookie->getExpiresTime());
        $this->assertStringNotContainsString('"u"', $cookie->getValue(), 'the value is encrypted, not readable JSON');
        $this->assertStringNotContainsString('{', $cookie->getValue());
    }

    public function test_bursts_are_capped_even_inside_the_free_tries(): void
    {
        config(['login_security.burst.attempts' => 3, 'login_security.account.free_attempts' => 1000]);
        $this->account('0911223344');

        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(self::GENERIC, $this->attempt('0911223344'));
        }

        $this->assertStringContainsString('Too many', $this->attempt('0911223344', self::PASSWORD), 'a fourth try inside a minute waits, even with the right password');
        $this->assertSame(self::GENERIC, $this->attempt('0922334455'), 'a different number from the same address is not held by that cap');
    }

    // ------------------------------------------------------------------ disabled accounts

    public function test_a_disabled_account_cannot_sign_in_and_gets_the_same_answer(): void
    {
        $user = $this->account('0911223344');
        $user->update(['status' => 'inactive']);

        $this->assertSame(self::GENERIC, $this->attempt('0911223344', self::PASSWORD));
        $this->assertGuest();
        $this->assertSame(self::GENERIC, $this->attempt('0977000000', self::PASSWORD), 'the same words as a number that is nobody\'s');

        $user->update(['status' => 'active']);
        $this->passTime(1000);
        $this->assertSame('OK', $this->attempt('0911223344', self::PASSWORD));
    }

    public function test_switching_an_account_off_and_on_is_recorded_with_who_did_it(): void
    {
        $t = $this->account('0911223344');

        $this->actingAs($this->owner)->patch("/staff/{$t->id}", ['title' => 'T', 'status' => 'inactive']);
        $this->actingAs($this->owner)->patch("/staff/{$t->id}", ['title' => 'T', 'status' => 'active']);

        $off = SecurityEvent::where('event', 'account_disabled')->firstOrFail();
        $on = SecurityEvent::where('event', 'account_enabled')->firstOrFail();
        $this->assertSame([$t->id, $this->owner->id], [$off->user_id, $off->actor_id]);
        $this->assertSame($this->owner->id, $on->actor_id);
    }

    // ------------------------------------------------------------------ the security log

    public function test_failures_cool_downs_locks_and_recoveries_are_recorded_without_secrets(): void
    {
        $user = $this->account('0911223344');
        for ($i = 0; $i < 3; $i++) {
            $this->attempt('0911223344');
        }
        $this->attempt('0911223344', self::PASSWORD);            // blocked during the wait
        $this->attempt('0911223344', self::PASSWORD);            // blocked again: still one entry for the stretch
        $this->passTime(20);
        $this->attempt('0911223344', self::PASSWORD);            // in

        $events = SecurityEvent::orderBy('id')->pluck('event')->all();
        $this->assertSame(3, collect($events)->filter(fn ($e) => $e === 'login_failed')->count());
        $this->assertContains('login_cooldown', $events);
        $this->assertSame(1, collect($events)->filter(fn ($e) => $e === 'login_blocked')->count());
        $this->assertContains('login_succeeded_after_failures', $events);

        $failed = SecurityEvent::where('event', 'login_failed')->first();
        $this->assertSame($user->id, $failed->user_id);
        $this->assertSame('+2519*****344', $failed->identifier, 'masked, never the full number');
        $this->assertSame('10.0.0.1', $failed->ip_address);
        $all = json_encode(SecurityEvent::all()->toArray());
        $this->assertStringNotContainsString(self::PASSWORD, $all);
        $this->assertStringNotContainsString('wrong', $all);
        $this->assertStringNotContainsString('+251911223344', $all);
    }

    public function test_a_number_that_is_nobodys_is_recorded_too_without_a_user(): void
    {
        $this->attempt('0977000000');

        $e = SecurityEvent::firstOrFail();
        $this->assertNull($e->user_id);
        $this->assertSame('+2519*****000', $e->identifier);
    }

    public function test_a_temporary_lock_is_recorded_with_how_long(): void
    {
        $this->account('0911223344');
        $this->failTimes('0911223344', 10);

        $lock = SecurityEvent::where('event', 'account_locked')->firstOrFail();
        $this->assertSame(30, $lock->context['lock_minutes']);
    }

    public function test_only_people_holding_view_security_log_specifically_see_the_security_log(): void
    {
        $this->attempt('0977000000');

        $this->actingAs($this->makeUser('teacher', $this->branchA->id))->get('/audit-log/security')->assertForbidden();

        // Holding the ordinary audit-log permission alone is deliberately not enough -- view-security-log
        // was split off from it precisely so this case is refused (see the migration/controller that did it).
        $this->actingAs($this->makeUser('branch_admin', $this->branchA->id, ['view-audit-log']))->get('/audit-log/security')->assertForbidden();

        $page = $this->props($this->actingAs($this->makeUser('auditor', null))->get('/audit-log/security')->assertOk());
        $this->assertSame('login_failed', $page['events']['data'][0]['event']);
        $this->assertSame('+2519*****000', json_decode(json_encode($page), true)['events']['data'][0]['identifier']);
    }

    public function test_the_audit_log_pages_security_link_only_shows_for_someone_who_can_actually_open_it(): void
    {
        $withBoth = $this->makeUser('branch_admin', $this->branchA->id, ['view-audit-log', 'view-security-log']);
        $withOnlyAuditLog = $this->makeUser('branch_admin', $this->branchA->id, ['view-audit-log']);

        $this->assertTrue($this->props($this->actingAs($withBoth)->get('/audit-log')->assertOk())['canViewSecurityLog']);
        $this->assertFalse($this->props($this->actingAs($withOnlyAuditLog)->get('/audit-log')->assertOk())['canViewSecurityLog']);
    }

    public function test_old_events_are_pruned(): void
    {
        SecurityEvent::create(['event' => 'login_failed', 'created_at' => now()->subDays(400)]);
        SecurityEvent::create(['event' => 'login_failed', 'created_at' => now()->subDays(2)]);

        $this->artisan('security:prune')->assertSuccessful();

        $this->assertSame(1, SecurityEvent::count());
    }

    // ------------------------------------------------------------------ administrator recovery

    public function test_an_administrator_reset_clears_the_lock_issues_a_temporary_password_and_is_recorded(): void
    {
        $t = $this->account('0911223344');
        $this->failTimes('0911223344', 10);
        $this->assertStringContainsString('Too many', $this->attempt('0911223344', self::PASSWORD));

        $this->actingAs($this->owner)->post("/staff/{$t->id}/password-reset")->assertSessionHas('credentials');
        $temporary = \Illuminate\Support\Facades\Crypt::decryptString(session('credentials')['password']);

        $this->assertSame('OK', $this->attempt('0911223344', $temporary), 'no wait is left over');
        $this->assertTrue($t->fresh()->must_change_password, 'the normal rule still applies: it must be replaced');
        $this->assertTrue(SecurityEvent::where('event', 'password_reset_by_admin')->where('actor_id', $this->owner->id)->where('user_id', $t->id)->exists());
        $this->assertTrue(AuditLog::where('action', 'staff.password_reset')->exists());
        $this->assertStringNotContainsString($temporary, json_encode(SecurityEvent::all()->toArray()));
    }

    public function test_an_administrator_can_let_someone_try_again_without_changing_their_password(): void
    {
        $t = $this->account('0911223344');
        $hash = $t->password;
        $this->failTimes('0911223344', 10);

        $this->actingAs($this->owner)->post("/staff/{$t->id}/unlock-sign-in")->assertSessionHasNoErrors();

        $this->assertSame($hash, $t->fresh()->password);
        $this->assertSame('OK', $this->attempt('0911223344', self::PASSWORD));
        $this->assertTrue(SecurityEvent::where('event', 'sign_in_unlocked_by_admin')->where('actor_id', $this->owner->id)->exists());
    }

    public function test_who_may_unlock_whom(): void
    {
        $t = $this->account('0911223344');
        $east = User::create(['school_id' => $this->school->id, 'branch_id' => $this->branchB->id, 'name' => 'East', 'phone' => '0944556677', 'login_phone' => '0944556677', 'password' => 'x-secret-1', 'status' => 'active']);
        $east->assignTier('teacher');
        $admin = $this->makeUser('branch_admin', $this->branchA->id, ['manage-staff']);
        $this->failTimes('0911223344', 10);

        $this->actingAs($this->makeUser('teacher', $this->branchA->id))->post("/staff/{$t->id}/unlock-sign-in")->assertForbidden();
        $this->actingAs($admin)->post("/staff/{$east->id}/unlock-sign-in")->assertForbidden();
        $this->actingAs($admin)->post("/staff/{$this->owner->id}/unlock-sign-in")->assertForbidden();
        $this->assertStringContainsString('Too many', $this->attempt('0911223344', self::PASSWORD));

        $this->actingAs($admin)->post("/staff/{$t->id}/unlock-sign-in")->assertSessionHasNoErrors();
        $this->assertSame('OK', $this->attempt('0911223344', self::PASSWORD));
    }

    public function test_the_staff_list_shows_who_is_paused_and_an_unlock_clears_it(): void
    {
        $t = $this->account('0911223344');
        $this->failTimes('0911223344', 10);

        $row = fn () => collect($this->props($this->actingAs($this->owner)->get('/staff'))['staff'])->firstWhere('id', $t->id);
        $this->assertTrue($row()['sign_in_lock']['waiting']);
        $this->assertTrue($row()['sign_in_lock']['locked']);

        $this->actingAs($this->owner)->post("/staff/{$t->id}/unlock-sign-in");
        $this->assertFalse($row()['sign_in_lock']['waiting']);
    }

    public function test_resetting_a_password_through_the_emailed_link_is_recorded_and_clears_the_lock(): void
    {
        $t = $this->account('0911223344');
        $t->update(['email' => 't@test.test']);
        $this->failTimes('0911223344', 10);

        event(new PasswordReset($t));

        $this->assertTrue(SecurityEvent::where('event', 'password_reset_by_email')->where('user_id', $t->id)->exists());
        $this->assertFalse(app(LoginThrottle::class)->status($t)['waiting']);
    }

    public function test_a_persons_own_password_change_is_recorded(): void
    {
        $t = $this->account('0911223344');

        $this->actingAs($t)->put('/password', ['current_password' => self::PASSWORD, 'password' => 'Brand-New-Pass!77', 'password_confirmation' => 'Brand-New-Pass!77']);

        $this->assertTrue(SecurityEvent::where('event', 'password_changed')->where('user_id', $t->id)->exists());
    }

    // ------------------------------------------------------------------ it is all server-side and configurable

    public function test_the_numbers_come_from_configuration(): void
    {
        config(['login_security.account.free_attempts' => 1, 'login_security.account.cooldowns' => [45]]);
        $this->account('0911223344');

        $this->attempt('0911223344');
        $this->attempt('0911223344');

        $this->assertStringContainsString('45 seconds', $this->attempt('0911223344', self::PASSWORD));
    }

    public function test_the_protection_needs_no_javascript_only_the_form_post(): void
    {
        $this->account('0911223344');
        for ($i = 0; $i < 3; $i++) {
            $this->post('/login', ['phone' => '0911223344', 'password' => 'wrong'], ['X-Requested-With' => null]);
        }

        $this->post('/login', ['phone' => '0911223344', 'password' => self::PASSWORD])->assertSessionHasErrors('phone');
        $this->assertGuest();
    }
}
