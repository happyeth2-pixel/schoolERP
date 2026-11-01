<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Security\LoginThrottle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

/**
 * "Improve account lifecycle management": every account (staff or parent) has a clear status - Active, Disabled,
 * Temporary password required, Locked/cooldown - and the same six actions behave the same way for both kinds of
 * account. This file walks the full matrix explicitly, for both, in one place: the deeper per-feature rules (branch
 * scoping, permission clamping, who a branch admin may reach, notifications) already live in StaffLifecycleTest and
 * ParentAccountTest and are not repeated here.
 */
class AccountLifecycleTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();   // the login throttle's array cache otherwise bleeds between tests in this file
        config(['login_security.burst.attempts' => 1000]);
        $this->buildSchool();
        $this->admin = $this->makeUser('branch_admin', $this->branchA->id, ['manage-staff', 'manage-parents']);
    }

    // ================================================================== the four statuses, for both kinds of account

    public static function accountKinds(): array
    {
        return [
            'staff' => ['staff'],
            'parent' => ['parent'],
        ];
    }

    private function makeAccount(string $kind): User
    {
        $phone = $kind === 'staff' ? '+251911000111' : '+251911000222';
        $u = User::create(['school_id' => $this->school->id, 'branch_id' => $kind === 'staff' ? $this->branchA->id : null, 'name' => ucfirst($kind).' Person', 'phone' => $phone, 'login_phone' => $phone, 'password' => 'Original-Pass1', 'status' => 'active']);
        $u->assignTier($kind === 'staff' ? 'teacher' : 'parent');

        return $u;
    }

    private function statusPage(string $kind): array
    {
        $url = $kind === 'staff' ? '/staff' : '/parents';

        return json_decode(json_encode($this->props($this->actingAs($this->admin)->get($url)->assertOk())), true)[$kind === 'staff' ? 'staff' : 'parents'];
    }

    private function row(string $kind, int $id): array
    {
        return collect($this->statusPage($kind))->firstWhere('id', $id);
    }

    /** @dataProvider accountKinds */
    public function test_status_active(string $kind): void
    {
        $u = $this->makeAccount($kind);

        $row = $this->row($kind, $u->id);
        $this->assertSame('active', $row['status']);
        $this->assertFalse($row['must_change_password']);
        $this->assertFalse($row['sign_in_lock']['locked']);
        $this->assertFalse($row['sign_in_lock']['waiting']);

        auth()->logout();
        $this->post('/login', ['phone' => $u->login_phone, 'password' => 'Original-Pass1'])->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($u);
        auth()->logout();
    }

    /** @dataProvider accountKinds */
    public function test_status_disabled(string $kind): void
    {
        $u = $this->makeAccount($kind);
        $endpoint = $kind === 'staff' ? "/staff/{$u->id}/deactivate" : "/parents/{$u->id}/deactivate";

        $this->actingAs($this->admin)->post($endpoint)->assertSessionHasNoErrors();

        $row = $this->row($kind, $u->id);
        $this->assertSame('inactive', $row['status']);

        auth()->logout();
        $this->post('/login', ['phone' => $u->login_phone, 'password' => 'Original-Pass1'])->assertSessionHasErrors('phone');
        $this->assertGuest();
    }

    /** @dataProvider accountKinds */
    public function test_status_temporary_password_required(string $kind): void
    {
        $u = $this->makeAccount($kind);
        $endpoint = $kind === 'staff' ? "/staff/{$u->id}/password-reset" : "/parents/{$u->id}/password-reset";

        $this->actingAs($this->admin)->post($endpoint)->assertSessionHasNoErrors();
        $temporary = \Illuminate\Support\Facades\Crypt::decryptString(session('credentials')['password']);
        auth()->logout();

        $row = $this->row($kind, $u->id);
        $this->assertTrue($row['must_change_password']);

        // signed in, but held on the "choose a new password" page until it is done
        auth()->logout();
        $this->post('/login', ['phone' => $u->login_phone, 'password' => $temporary]);
        $this->get('/dashboard')->assertRedirect(route('password.required'));
        auth()->logout();
    }

    /** @dataProvider accountKinds */
    public function test_status_locked_cooldown(string $kind): void
    {
        $u = $this->makeAccount($kind);

        for ($i = 0; $i < 3; $i++) {
            $this->post('/login', ['phone' => $u->login_phone, 'password' => 'wrong']);
        }
        $row = $this->row($kind, $u->id);
        $this->assertFalse($row['sign_in_lock']['locked'], 'a few failures are a cooldown, not a lock');
        $this->assertTrue($row['sign_in_lock']['waiting']);

        for ($i = 0; $i < 10; $i++) {
            app(LoginThrottle::class)->failed(request(), LoginThrottle::keyFor($u->login_phone), $u, $u->login_phone);
        }
        $row = $this->row($kind, $u->id);
        $this->assertTrue($row['sign_in_lock']['locked'], 'ten failures is a hard lock, not just a cooldown');
        $this->assertTrue($row['sign_in_lock']['waiting']);

        // the password is not even checked while the account is paused
        auth()->logout();
        $this->post('/login', ['phone' => $u->login_phone, 'password' => 'Original-Pass1'])->assertSessionHasErrors('phone');
        $this->assertGuest();
    }

    // ================================================================== the six actions, for both kinds

    /** @dataProvider accountKinds */
    public function test_action_view_shows_details_without_ever_showing_a_password(string $kind): void
    {
        $u = $this->makeAccount($kind);
        $auditor = $this->makeUser('auditor', null);

        $page = $this->statusPage($kind);
        $row = collect($page)->firstWhere('id', $u->id);
        $this->assertArrayNotHasKey('password', $row);
        $this->assertArrayHasKey('status', $row);
        $this->assertArrayHasKey('last_login_at', $row);

        // an auditor (read-only) can also open the page and see the same status fields
        $url = $kind === 'staff' ? '/staff' : '/parents';
        $auditorPage = json_decode(json_encode($this->props($this->actingAs($auditor)->get($url)->assertOk())), true);
        $this->assertFalse($auditorPage['canManage'] ?? false);
        $this->assertTrue($auditorPage['canView'] ?? false);
    }

    /** @dataProvider accountKinds */
    public function test_action_edit_permitted_details(string $kind): void
    {
        $u = $this->makeAccount($kind);
        $endpoint = $kind === 'staff' ? "/staff/{$u->id}" : "/parents/{$u->id}";
        $payload = $kind === 'staff' ? ['name' => 'New Name', 'title' => 'T', 'status' => 'active'] : ['name' => 'New Name'];

        $this->actingAs($this->admin)->patch($endpoint, $payload)->assertSessionHasNoErrors();

        $this->assertSame('New Name', $u->fresh()->name);
    }

    /** @dataProvider accountKinds */
    public function test_action_reset_password_generates_shows_once_and_forces_a_change(string $kind): void
    {
        $u = $this->makeAccount($kind);
        $u->update(['must_change_password' => false]);
        $endpoint = $kind === 'staff' ? "/staff/{$u->id}/password-reset" : "/parents/{$u->id}/password-reset";

        $this->actingAs($this->admin)->post($endpoint)->assertSessionHasNoErrors();

        $flashed = session('credentials');
        $this->assertNotEmpty($flashed['password'], 'a secure temporary password is shown once, to the administrator');
        $temporary = \Illuminate\Support\Facades\Crypt::decryptString($flashed['password']);
        $this->assertNotSame($temporary, $flashed['password'], 'never shown in plain text, even in the flash payload');
        $fresh = $u->fresh();
        $this->assertTrue((bool) $fresh->must_change_password, 'marked as requiring a password change');
        $this->assertTrue(\Hash::check($temporary, $fresh->password), 'stored hashed');
        $this->assertFalse(\Hash::check('Original-Pass1', $fresh->password), 'the old password no longer works');

        // at next login: forced to create a new password
        auth()->logout();
        $this->post('/login', ['phone' => $u->login_phone, 'password' => $temporary]);
        $this->get('/dashboard')->assertRedirect(route('password.required'));
        $this->put(route('password.required.update'), ['current_password' => $temporary, 'password' => 'Brand-New-Pass9', 'password_confirmation' => 'Brand-New-Pass9'])->assertSessionHasNoErrors();
        $this->assertFalse((bool) $u->fresh()->must_change_password);
        $this->get('/dashboard')->assertOk();
        auth()->logout();
    }

    /** @dataProvider accountKinds */
    public function test_action_disable_prevents_login_and_preserves_every_kind_of_history(string $kind): void
    {
        $u = $this->makeAccount($kind);
        $enrollment = \App\Models\Enrollment::where('student_id', $this->studentA->id)->firstOrFail();
        $attendance = \App\Models\AttendanceRecord::create(['enrollment_id' => $enrollment->id, 'date' => '2026-10-01', 'status' => 'present', 'marked_by' => $u->id]);
        AuditLog::record($u, 'student.updated', $this->studentA, ['x' => 1], ['x' => 2]);
        $auditCountBefore = AuditLog::where('user_id', $u->id)->count();
        $endpoint = $kind === 'staff' ? "/staff/{$u->id}/deactivate" : "/parents/{$u->id}/deactivate";

        $this->actingAs($this->admin)->post($endpoint)->assertSessionHasNoErrors();

        $this->assertSame('inactive', $u->fresh()->status);
        auth()->logout();
        $this->post('/login', ['phone' => $u->login_phone, 'password' => 'Original-Pass1'])->assertSessionHasErrors('phone');
        $this->assertGuest();

        // the user row itself, and everything it is attributed to, is untouched
        $this->assertNotNull(User::find($u->id), 'not deleted');
        $this->assertSame($u->id, $attendance->fresh()->marked_by);
        $this->assertSame($auditCountBefore, AuditLog::where('user_id', $u->id)->count(), 'their own audit trail is not rewritten');
        $this->assertSame(1, SecurityEvent::where('event', 'account_disabled')->where('user_id', $u->id)->count());
    }

    /** @dataProvider accountKinds */
    public function test_action_reactivate_restores_sign_in_with_the_existing_password_by_default(string $kind): void
    {
        $u = $this->makeAccount($kind);
        $u->update(['status' => 'inactive']);
        $reactivate = $kind === 'staff' ? "/staff/{$u->id}/reactivate" : "/parents/{$u->id}/reactivate";

        $this->actingAs($this->admin)->post($reactivate)->assertSessionHasNoErrors();

        $this->assertSame('active', $u->fresh()->status);
        auth()->logout();
        $this->post('/login', ['phone' => $u->login_phone, 'password' => 'Original-Pass1'])->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($u);
        auth()->logout();
        $this->assertSame(1, SecurityEvent::where('event', 'account_enabled')->where('user_id', $u->id)->count());
    }

    /** @dataProvider accountKinds */
    public function test_action_reactivate_can_also_require_a_new_password_when_appropriate(string $kind): void
    {
        $u = $this->makeAccount($kind);
        $u->update(['status' => 'inactive']);
        $reactivate = $kind === 'staff' ? "/staff/{$u->id}/reactivate" : "/parents/{$u->id}/reactivate";

        $this->actingAs($this->admin)->post($reactivate, ['reset_password' => true])->assertSessionHasNoErrors();
        $temporary = \Illuminate\Support\Facades\Crypt::decryptString(session('credentials')['password']);

        $fresh = $u->fresh();
        $this->assertSame('active', $fresh->status);
        $this->assertTrue((bool) $fresh->must_change_password);
        $this->assertFalse(\Hash::check('Original-Pass1', $fresh->password), 'the old password no longer works once a reset was requested');
        auth()->logout();
        $this->post('/login', ['phone' => $u->login_phone, 'password' => $temporary]);
        $this->get('/dashboard')->assertRedirect(route('password.required'));
        auth()->logout();
    }

    // ================================================================== password rule

    /** @dataProvider accountKinds */
    public function test_administrators_never_see_a_permanent_password_anywhere(string $kind): void
    {
        $u = $this->makeAccount($kind);
        $u->update(['password' => 'Some-Real-Password1']);

        foreach (['/staff', '/parents'] as $url) {
            $body = $this->actingAs($this->admin)->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('Some-Real-Password1', $body);
            $this->assertStringNotContainsString($u->fresh()->password, $body, 'not even the hash');
        }
    }

    /**
     * A bug this phase's browser test found: if an account already needs a new password (e.g. it was disabled before
     * its temporary password was ever used) and is then reactivated WITH a fresh reset, the flag must stay true. The
     * password goes from one value to another (dirty), but must_change_password goes true -> true (NOT dirty by
     * Eloquent's tracking, since the value doesn't change) - a hook that only acted on isDirty() wrongly cleared it.
     */
    /** @dataProvider accountKinds */
    public function test_reactivating_with_reset_keeps_the_flag_true_even_when_it_was_already_true(string $kind): void
    {
        $u = $this->makeAccount($kind);
        $u->forceFill(['status' => 'inactive', 'must_change_password' => true])->save();
        $reactivate = $kind === 'staff' ? "/staff/{$u->id}/reactivate" : "/parents/{$u->id}/reactivate";

        $this->actingAs($this->admin)->post($reactivate, ['reset_password' => true])->assertSessionHasNoErrors();

        $this->assertTrue((bool) $u->fresh()->must_change_password);
    }

    /** The same bug, reached the other way: resetting a password twice before the person ever signs in. */
    public function test_resetting_a_password_twice_in_a_row_still_requires_a_change_the_second_time(): void
    {
        $u = $this->makeAccount('staff');   // must_change_password is already true from creation

        $this->actingAs($this->admin)->post("/staff/{$u->id}/password-reset");
        $this->assertTrue((bool) $u->fresh()->must_change_password);

        $this->actingAs($this->admin)->post("/staff/{$u->id}/password-reset");
        $this->assertTrue((bool) $u->fresh()->must_change_password, 'the second reset must not silently clear the flag');
    }

    /** An ordinary, non-administrator password change (Forgot password, the profile page) still clears the flag as before. */
    public function test_an_ordinary_password_change_still_clears_the_flag_even_when_it_was_already_true(): void
    {
        $u = $this->makeAccount('staff');
        $u->forceFill(['must_change_password' => true])->save();

        $u->update(['password' => 'A-Different-Pass2']);

        $this->assertFalse($u->fresh()->must_change_password);
    }

    // ================================================================== audit: created, disabled, reactivated, reset, role/permission, phone

    public function test_account_created_is_audited(): void
    {
        $this->actingAs($this->admin)->post('/staff', ['name' => 'New Teacher', 'phone' => '0911000333', 'role' => 'teacher'])->assertSessionHasNoErrors();
        $this->assertNotNull(AuditLog::where('action', 'staff.role_assigned')->first());

        $this->actingAs($this->admin)->post('/parents', ['name' => 'New Parent', 'phone' => '0911000444'])->assertSessionHasNoErrors();
        $this->assertNotNull(AuditLog::where('action', 'parent.account_created')->first());
    }

    /** @dataProvider accountKinds */
    public function test_account_disabled_and_reactivated_are_both_in_the_general_audit_trail_with_readable_wording(string $kind): void
    {
        $u = $this->makeAccount($kind);
        $disable = $kind === 'staff' ? "/staff/{$u->id}/deactivate" : "/parents/{$u->id}/deactivate";
        $reactivate = $kind === 'staff' ? "/staff/{$u->id}/reactivate" : "/parents/{$u->id}/reactivate";

        $this->actingAs($this->admin)->post($disable);
        $off = AuditLog::where('action', 'user.status_changed')->where('auditable_id', $u->id)->latest('id')->firstOrFail();
        $this->assertSame('active', $off->before['status']);
        $this->assertSame('inactive', $off->after['status']);
        $this->assertSame($this->admin->id, $off->user_id);

        $this->actingAs($this->admin)->post($reactivate);
        $on = AuditLog::where('action', 'user.status_changed')->where('auditable_id', $u->id)->latest('id')->firstOrFail();
        $this->assertSame('inactive', $on->before['status']);
        $this->assertSame('active', $on->after['status']);
    }

    public function test_password_reset_is_audited_without_the_password(): void
    {
        $u = $this->makeAccount('staff');
        $this->actingAs($this->admin)->post("/staff/{$u->id}/password-reset");

        $log = AuditLog::where('action', 'staff.password_reset')->where('auditable_id', $u->id)->firstOrFail();
        $this->assertSame([], array_diff(array_keys($log->after ?? []), ['must_change_password']));
        $everything = json_encode(AuditLog::all()->toArray());
        $this->assertStringNotContainsString(session('credentials')['password'], $everything);
    }

    public function test_role_and_permission_changes_are_audited(): void
    {
        $u = $this->makeUser('branch_admin', $this->branchA->id, ['manage-students']);
        $this->actingAs($this->admin)->patch("/staff/{$u->id}", ['title' => 'T', 'status' => 'active', 'permissions' => ['manage-students', 'manage-parents']])->assertSessionHasNoErrors();

        $this->assertNotNull(AuditLog::where('action', 'staff.permissions_changed')->where('auditable_id', $u->id)->first());
    }

    public function test_phone_number_changes_are_audited(): void
    {
        $u = $this->makeAccount('staff');
        $this->actingAs($this->admin)->patch("/staff/{$u->id}", ['title' => 'T', 'status' => 'active', 'phone' => '0922556677'])->assertSessionHasNoErrors();

        $log = AuditLog::where('action', 'staff.phone_changed')->where('auditable_id', $u->id)->firstOrFail();
        $this->assertSame('+251911000111', $log->before['phone']);
        $this->assertSame('+251922556677', $log->after['phone']);
    }

    public function test_no_plaintext_password_is_ever_written_to_the_audit_or_security_log(): void
    {
        $u = $this->makeAccount('staff');
        $secrets = [];

        $this->actingAs($this->admin)->post("/staff/{$u->id}/password-reset");
        $secrets[] = \Illuminate\Support\Facades\Crypt::decryptString(session('credentials')['password']);
        $this->actingAs($this->admin)->post("/staff/{$u->id}/deactivate");
        $this->actingAs($this->admin)->post("/staff/{$u->id}/reactivate", ['reset_password' => true]);
        $secrets[] = \Illuminate\Support\Facades\Crypt::decryptString(session('credentials')['password']);

        $everything = json_encode(AuditLog::all()->toArray()).json_encode(SecurityEvent::all()->toArray());
        foreach (array_unique($secrets) as $secret) {
            $this->assertStringNotContainsString($secret, $everything);
        }
    }
}
