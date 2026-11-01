<?php

namespace Tests\Feature;

use App\Models\AccountInvitation;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\School;
use App\Models\User;
use App\Notifications\AccountInvitationNotification;
use App\Services\AccountOnboarding;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Tests\TestCase;

class AccountInvitationTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private Branch $branch;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 09:00:00');
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->school = School::create(['name' => 'Test School', 'code' => 'TST']);
        $this->branch = Branch::create(['school_id' => $this->school->id, 'name' => 'Main', 'code' => 'MAIN', 'is_main' => true]);
        $this->owner = $this->makeUser('Owner', 'owner', null);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ------------------------------------------------------ creating staff

    public function test_an_invitation_is_a_one_time_link_and_only_its_hash_is_stored(): void
    {
        $teacher = $this->makeTeacher();
        $invite = AccountOnboarding::invite($teacher, $this->owner);
        $token = basename($invite['url']);

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{40}$/', $token);
        $this->assertSame('New Teacher', $invite['name']);
        $this->assertSame(\App\Support\SchoolCalendar::dateTime(Carbon::now()->addHours(72), $this->school), $invite['expires_at']);

        $user = $teacher;
        $row = AccountInvitation::where('user_id', $user->id)->firstOrFail();
        $this->assertSame(hash('sha256', $token), $row->token_hash);
        $this->assertStringNotContainsString($token, json_encode(DB::table('account_invitations')->get()), 'the plain token must never be stored');
        $this->assertTrue($row->expires_at->equalTo(Carbon::now()->addHours(72)));

        $this->assertTrue(AuditLog::where('action', 'account.invited')->where('auditable_id', $user->id)->exists());
        $this->post('/login', ['phone' => $user->email, 'password' => 'password'])->assertSessionHasErrors('phone');
        $this->assertGuest();
    }

    public function test_the_invited_person_sets_their_password_and_is_logged_in(): void
    {
        $url = $this->inviteTeacher();

        $this->get($url)->assertOk();
        $props = $this->pageProps($this->get($url));
        $this->assertTrue($props['valid']);
        $this->assertSame('New Teacher', $props['name']);
        $this->assertSame('new.teacher@test.test', $props['email']);

        $this->post($url, ['password' => 'Str0ng-Password!42', 'password_confirmation' => 'Str0ng-Password!42'])
            ->assertRedirect(route('dashboard'));

        $user = User::where('email', 'new.teacher@test.test')->first();
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->email_verified_at);

        $row = AccountInvitation::where('user_id', $user->id)->first();
        $this->assertNotNull($row->used_at);
        $this->assertSame('127.0.0.1', $row->used_ip);
        $this->assertTrue(AuditLog::where('action', 'account.invitation_accepted')->exists());

        $this->post('/logout');
        $this->post('/login', ['phone' => $user->email, 'password' => 'Str0ng-Password!42'])->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }

    // ------------------------------------------------------ one time, expiry

    public function test_a_link_works_exactly_once(): void
    {
        $url = $this->inviteTeacher();
        $data = ['password' => 'Str0ng-Password!42', 'password_confirmation' => 'Str0ng-Password!42'];

        $this->post($url, $data);
        $this->post('/logout');

        $this->assertSame(['valid' => false], $this->pageProps($this->get($url)));

        $this->post($url, ['password' => 'Another-Password!99', 'password_confirmation' => 'Another-Password!99'])->assertRedirect($url);
        $this->assertGuest();
        $this->assertTrue(\Hash::check('Str0ng-Password!42', User::where('email', 'new.teacher@test.test')->first()->password), 'a used link changed the password');
    }

    public function test_the_link_expires_after_seventy_two_hours(): void
    {
        $url = $this->inviteTeacher();

        Carbon::setTestNow(Carbon::now()->addHours(72)->subMinute());
        $this->assertTrue($this->pageProps($this->get($url))['valid']);

        Carbon::setTestNow(Carbon::now()->addMinutes(2));
        $this->assertSame(['valid' => false], $this->pageProps($this->get($url)));
        $this->post($url, ['password' => 'Str0ng-Password!42', 'password_confirmation' => 'Str0ng-Password!42']);
        $this->assertGuest();
    }

    public function test_a_weak_or_mismatched_password_is_rejected_and_the_link_stays_usable(): void
    {
        $url = $this->inviteTeacher();

        $this->post($url, ['password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
        $this->post($url, ['password' => 'Str0ng-Password!42', 'password_confirmation' => 'different'])->assertSessionHasErrors('password');
        $this->assertGuest();

        $this->assertTrue($this->pageProps($this->get($url))['valid']);
        $this->post($url, ['password' => 'Str0ng-Password!42', 'password_confirmation' => 'Str0ng-Password!42'])->assertRedirect(route('dashboard'));
    }

    public function test_every_kind_of_dead_link_looks_identical(): void
    {
        $unknown = $this->pageProps($this->get('/invitation/'.Str::random(40)));

        $used = $this->inviteTeacher('used@test.test');
        $this->post($used, ['password' => 'Str0ng-Password!42', 'password_confirmation' => 'Str0ng-Password!42']);
        $this->post('/logout');

        $revoked = $this->inviteTeacher('revoked@test.test');
        AccountInvitation::revokePending(User::where('email', 'revoked@test.test')->value('id'));

        $expired = $this->inviteTeacher('expired@test.test');
        AccountInvitation::whereHas('user', fn ($q) => $q->where('email', 'expired@test.test'))->update(['expires_at' => now()->subMinute()]);

        $inactive = $this->inviteTeacher('inactive@test.test');
        User::where('email', 'inactive@test.test')->update(['status' => 'inactive']);

        foreach ([$used, $revoked, $expired, $inactive] as $url) {
            $this->assertSame($unknown, $this->pageProps($this->get($url)), "{$url} revealed why it is dead");
        }
        $this->assertSame(['valid' => false], $unknown);
    }

    // ------------------------------------------------------ resend

    public function test_a_new_invitation_revokes_the_old_one_and_only_works_until_a_password_is_set(): void
    {
        $old = $this->inviteTeacher();
        $teacher = User::where('email', 'new.teacher@test.test')->first();

        $new = AccountOnboarding::invite($teacher, $this->owner)['url'];
        $this->app['auth']->guard('web')->logout();
        $this->flushSession();

        $this->assertNotSame($old, $new);
        $this->assertSame(['valid' => false], $this->pageProps($this->get($old)));
        $this->assertTrue($this->pageProps($this->get($new))['valid']);

        $this->post($new, ['password' => 'Str0ng-Password!42', 'password_confirmation' => 'Str0ng-Password!42']);
        $this->post('/logout');
    }

    // ------------------------------------------------------ forgot password is separate

    public function test_forgot_password_still_works_on_its_own(): void
    {
        $user = $this->makeUser('Regular Staff', 'teacher', $this->branch->id);
        Notification::fake();

        $this->post('/forgot-password', ['email' => $user->email]);
        Notification::assertSentTo($user, ResetPassword::class);

        $token = Password::createToken($user);
        $this->post('/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'Brand-New-Pass!77', 'password_confirmation' => 'Brand-New-Pass!77'])
            ->assertRedirect(route('login'));

        $this->post('/login', ['phone' => $user->email, 'password' => 'Brand-New-Pass!77']);
        $this->assertAuthenticatedAs($user);
    }

    public function test_resetting_the_password_another_way_retires_a_pending_invitation(): void
    {
        $url = $this->inviteTeacher();
        $user = User::where('email', 'new.teacher@test.test')->first();
        $this->assertTrue($this->pageProps($this->get($url))['valid']);

        $this->post('/reset-password', ['token' => Password::createToken($user), 'email' => $user->email, 'password' => 'Reset-Pass-Word!55', 'password_confirmation' => 'Reset-Pass-Word!55']);

        $this->assertSame(['valid' => false], $this->pageProps($this->get($url)));
    }

    // ------------------------------------------------------ delivery

    public function test_it_is_emailed_when_a_real_mailer_is_configured_and_not_otherwise(): void
    {
        Notification::fake();
        $a = $this->makeTeacher('a@test.test');
        $this->assertFalse(AccountOnboarding::invite($a, $this->owner)['emailed']);
        Notification::assertNothingSent();                       // mailer is "array" in tests, like "log" in dev

        config(['mail.default' => 'smtp']);
        $user = $this->makeTeacher('b@test.test');
        $summary = AccountOnboarding::invite($user, $this->owner);
        Notification::assertSentTo($user, AccountInvitationNotification::class, function ($n) use ($user, $summary) {
            return str_contains($n->toMail($user)->actionUrl, '/invitation/') && $n->toMail($user)->actionUrl === $summary['url'];
        });
        $this->assertTrue($summary['emailed']);
    }

    // A new guardian created at enrollment now gets a temporary password handed to the registrar, the same way
    // ParentController::store() creates any other parent account -- no email, no invitation link. See
    // StudentNamesAndGuardiansTest::test_a_new_guardian_created_at_enrollment_gets_a_temporary_password_not_an_email_invite.

    // ------------------------------------------------------ hardening

    public function test_the_invitation_page_is_rate_limited_never_cached_and_needs_https_in_production(): void
    {
        $url = $this->inviteTeacher();
        $response = $this->get($url);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('noindex', $response->headers->get('X-Robots-Tag'));

        for ($i = 1; $i <= 9; $i++) {
            $this->get($url)->assertOk();
        }
        $this->get($url)->assertStatus(429);
    }

    public function test_https_is_enforced_on_invitation_links_in_production(): void
    {
        $url = $this->inviteTeacher();
        config(['app.force_https' => true]);

        $this->get('http://school.test/invitation/'.basename($url))->assertStatus(301);
    }

    // ------------------------------------------------------ helpers

    private function makeUser(string $name, string $role, ?int $branchId): User
    {
        $user = User::create([
            'school_id' => $this->school->id, 'branch_id' => $branchId, 'name' => $name,
            'email' => Str::slug($name).'@test.test', 'password' => 'password', 'status' => 'active', 'email_verified_at' => now(),
        ]);
        $user->assignTier($role);

        return $user;
    }

    /** A teacher whose password nobody knows (accounts are invited by link now; staff get temporary passwords instead). */
    private function makeTeacher(string $email = 'new.teacher@test.test'): User
    {
        $user = User::create(['school_id' => $this->school->id, 'branch_id' => $this->branch->id, 'name' => 'New Teacher', 'email' => $email, 'password' => Str::random(30), 'status' => 'active']);
        $user->assignTier('teacher', 'Teacher');

        return $user;
    }

    /** An invitation is issued for a teacher; returns the one-time link, then signs everybody out. */
    private function inviteTeacher(string $email = 'new.teacher@test.test'): string
    {
        $url = AccountOnboarding::invite($this->makeTeacher($email), $this->owner)['url'];
        $this->app['auth']->guard('web')->logout();
        $this->flushSession();

        return $url;
    }

    private function pageProps($response): array
    {
        $props = $response->original->getData()['page']['props'];

        return array_diff_key($props, array_flip(['errors', 'locale', 'auth', 'flash', 'notifications', 'monitoringUserPseudonym', 'today', 'parentChildren']));
    }
}
