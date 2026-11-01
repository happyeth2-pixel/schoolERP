<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\Enrollment;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

/**
 * The staff account lifecycle: an administrator creates an account (no email needed), hands over a temporary
 * password shown once, the person signs in with their phone number and must choose their own password; the account
 * can be reset, switched off (never deleted: everything they did stays) and switched on again.
 */
class StaffLifecycleTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchool();
    }

    private function payload(array $over = []): array
    {
        return array_merge(['name' => 'Tigist Alemu', 'phone' => '0922 33 44 55', 'title' => 'Maths teacher', 'role' => 'teacher', 'branch_id' => $this->branchA->id], $over);
    }

    private function create(array $over = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->owner)->post('/staff', $this->payload($over));
    }

    private function temporary(): string
    {
        return Crypt::decryptString(session('credentials')['password']);
    }

    private function account(string $phone = '+251922334455'): User
    {
        return User::where('login_phone', $phone)->firstOrFail();
    }

    private function admin(array $permissions, ?int $branchId = null): User
    {
        return $this->makeUser('branch_admin', $branchId ?? $this->branchA->id, $permissions);
    }

    // ------------------------------------------------------------------ creating an account

    /** The phone number is typed once, not twice -- a "type it again" confirmation field used to be
     * required here and was dropped as needless friction once a number is already typed correctly. */
    public function test_the_phone_number_is_typed_only_once(): void
    {
        $this->create()->assertSessionHasNoErrors();

        $this->assertNotNull($this->account());
    }

    public function test_an_account_is_created_without_any_email(): void
    {
        $this->create()->assertSessionHasNoErrors()->assertSessionHas('credentials');

        $u = $this->account();
        $this->assertNull($u->email);
        $this->assertSame('+251922334455', $u->login_phone);
        $this->assertSame('active', $u->status);
        $this->assertTrue($u->hasRole('teacher'));
        $this->assertSame($this->branchA->id, $u->branch_id);
        $this->assertSame('Maths teacher', $u->title);
        $this->assertTrue((bool) $u->must_change_password);
    }

    public function test_the_success_details_carry_everything_the_screen_shows_and_the_password_is_encrypted(): void
    {
        $this->create();
        $c = session('credentials');

        $this->assertSame('Tigist Alemu', $c['name']);
        $this->assertSame('teacher', $c['role']);
        $this->assertSame('Main', $c['branch']);
        $this->assertNotEmpty($c['phone']);
        $this->assertNotSame($this->temporary(), $c['password'], 'the flash never holds the password readable');
        $this->assertTrue(\Hash::check($this->temporary(), $this->account()->password));
    }

    public function test_a_generated_temporary_password_is_easy_to_read_and_signs_in(): void
    {
        $this->create();
        $password = $this->temporary();

        $this->assertSame(10, strlen($password));
        $this->assertDoesNotMatchRegularExpression('/[0OIl1o]/', $password);

        auth()->logout();
        $this->post('/login', ['phone' => '0922334455', 'password' => $password])->assertRedirect();
        $this->assertAuthenticatedAs($this->account());
    }

    public function test_an_administrator_can_type_the_temporary_password_and_it_is_used_and_audited(): void
    {
        $this->create(['temporary_password' => 'Tempo1234'])->assertSessionHasNoErrors();

        $this->assertSame('Tempo1234', $this->temporary());
        $this->assertTrue(\Hash::check('Tempo1234', $this->account()->password));
        $this->assertTrue((bool) $this->account()->must_change_password, 'a typed one is still only temporary');

        $log = AuditLog::where('action', 'account.temporary_password_issued')->latest('id')->firstOrFail();
        $this->assertTrue($log->after['chosen_by_administrator'] ?? false);
        $this->assertStringNotContainsString('Tempo1234', json_encode(AuditLog::all()->toArray()));
    }

    public function test_a_weak_typed_temporary_password_is_refused(): void
    {
        foreach (['short1', 'onlyletters', '12345678'] as $weak) {
            $this->create(['temporary_password' => $weak, 'phone' => '0955000001'])->assertSessionHasErrors('temporary_password');
        }
        $this->assertNull(User::where('login_phone', '+251955000001')->first());
    }

    public function test_email_stays_optional_contact_information_and_is_kept_when_given(): void
    {
        $this->create(['email' => 'tigist@school.test'])->assertSessionHasNoErrors();

        $this->assertSame('tigist@school.test', $this->account()->email);
    }

    public function test_the_credentials_are_flashed_once_only(): void
    {
        $this->create();
        $this->assertNotNull(session('credentials'));

        $this->get('/staff')->assertOk();
        $this->assertNull(session('credentials'), 'a second page load no longer has them');
    }

    // ------------------------------------------------------------------ the class a teacher looks after

    public function test_a_new_teacher_can_be_made_class_teacher_in_the_same_step(): void
    {
        $this->create(['class_teacher_section_id' => $this->sectionA->id])->assertSessionHasNoErrors();

        $this->assertSame($this->account()->id, $this->sectionA->fresh()->class_teacher_id);
        $this->assertSame('G1 A', session('credentials')['class']);
        $this->assertSame('G1 A', AuditLog::where('action', 'staff.role_assigned')->latest('id')->first()->after['class_teacher_of']);
    }

    public function test_the_class_is_optional(): void
    {
        $this->create()->assertSessionHasNoErrors();
        $this->assertNull($this->sectionA->fresh()->class_teacher_id);
    }

    public function test_a_class_is_only_for_a_teacher(): void
    {
        $this->create(['role' => 'accountant', 'class_teacher_section_id' => $this->sectionA->id])->assertSessionHasErrors('class_teacher_section_id');
        $this->assertNull(User::where('login_phone', '+251922334455')->first(), 'nothing half-created');
    }

    public function test_a_class_in_another_branch_is_refused(): void
    {
        $this->create(['class_teacher_section_id' => $this->sectionB->id])->assertSessionHasErrors('class_teacher_section_id');
        $this->assertNull($this->sectionB->fresh()->class_teacher_id);
    }

    public function test_a_class_that_already_has_a_class_teacher_is_never_silently_taken_over(): void
    {
        $existing = $this->makeUser('teacher', $this->branchA->id);
        $this->sectionA->update(['class_teacher_id' => $existing->id]);

        $this->create(['class_teacher_section_id' => $this->sectionA->id])->assertSessionHasErrors('class_teacher_section_id');
        $this->assertSame($existing->id, $this->sectionA->fresh()->class_teacher_id);
        $this->assertNull(User::where('login_phone', '+251922334455')->first());
    }

    public function test_someone_who_cannot_manage_classes_cannot_choose_one(): void
    {
        $admin = $this->admin(['manage-staff']);

        $this->create(['class_teacher_section_id' => $this->sectionA->id], $admin)->assertSessionHasErrors('class_teacher_section_id');

        $props = $this->props($this->actingAs($admin)->get('/staff/create')->assertOk());
        $this->assertFalse($props['canAssignClass']);
        $this->assertSame([], $props['classesWithoutTeacher']);
    }

    public function test_the_wizard_offers_only_free_classes_of_the_current_year_in_scope(): void
    {
        $taken = \App\Models\Section::create(['branch_id' => $this->branchA->id, 'grade_id' => $this->grade->id, 'academic_year_id' => $this->year->id, 'name' => 'Taken', 'class_teacher_id' => $this->makeUser('teacher', $this->branchA->id)->id]);

        $owner = $this->props($this->actingAs($this->owner)->get('/staff/create')->assertOk());
        $this->assertTrue($owner['canAssignClass']);
        $ids = array_column($owner['classesWithoutTeacher'], 'id');
        $this->assertContains($this->sectionA->id, $ids);
        $this->assertContains($this->sectionB->id, $ids);
        $this->assertNotContains($taken->id, $ids);

        $mine = $this->props($this->actingAs($this->admin(['manage-staff', 'manage-classes']))->get('/staff/create')->assertOk());
        $this->assertSame([$this->sectionA->id], array_column($mine['classesWithoutTeacher'], 'id'));
    }

    // ------------------------------------------------------------------ first login

    public function test_the_first_sign_in_is_forced_to_choose_a_permanent_password(): void
    {
        $this->create();
        $temporary = $this->temporary();
        auth()->logout();

        $this->post('/login', ['phone' => '0922334455', 'password' => $temporary]);
        $this->get('/dashboard')->assertRedirect(route('password.required'));

        $this->put(route('password.required.update'), ['current_password' => $temporary, 'password' => 'My-Own-Pass9', 'password_confirmation' => 'My-Own-Pass9'])->assertSessionHasNoErrors();

        $u = $this->account()->fresh();
        $this->assertFalse((bool) $u->must_change_password);
        $this->assertTrue(\Hash::check('My-Own-Pass9', $u->password));
        $this->assertFalse(\Hash::check($temporary, $u->password), 'the temporary password no longer works');
        $this->get('/dashboard')->assertOk();
    }

    // ------------------------------------------------------------------ authorization

    public function test_only_people_who_manage_staff_can_create_switch_off_or_on(): void
    {
        $target = $this->makeUser('teacher', $this->branchA->id);
        $cannot = $this->admin(['manage-students']);

        $this->create([], $cannot)->assertForbidden();
        $this->actingAs($cannot)->post("/staff/{$target->id}/deactivate")->assertForbidden();
        $this->actingAs($cannot)->post("/staff/{$target->id}/reactivate")->assertForbidden();
        $this->actingAs($this->makeUser('teacher', $this->branchA->id))->post("/staff/{$target->id}/deactivate")->assertForbidden();
        $this->assertSame('active', $target->fresh()->status);
    }

    public function test_a_branch_admin_cannot_switch_off_another_branchs_staff_or_an_owner(): void
    {
        $actor = $this->admin(['manage-staff']);
        $east = $this->makeUser('teacher', $this->branchB->id);

        $this->actingAs($actor)->post("/staff/{$east->id}/deactivate")->assertForbidden();
        $this->actingAs($actor)->post("/staff/{$this->owner->id}/deactivate")->assertForbidden();
        $this->assertSame('active', $east->fresh()->status);
        $this->assertSame('active', $this->owner->fresh()->status);
    }

    public function test_staff_of_another_school_cannot_be_reached(): void
    {
        $other = \App\Models\School::create(['name' => 'Other', 'code' => 'OTH']);
        $stranger = User::create(['school_id' => $other->id, 'name' => 'X', 'email' => 'x@o.test', 'password' => 'x', 'status' => 'active']);
        $stranger->assignTier('teacher');

        $this->actingAs($this->owner)->post("/staff/{$stranger->id}/deactivate")->assertForbidden();
        $this->assertSame('active', $stranger->fresh()->status);
    }

    // ------------------------------------------------------------------ switching an account off

    public function test_a_switched_off_account_cannot_sign_in_with_the_right_password(): void
    {
        $this->create(['temporary_password' => 'Tempo1234']);
        $u = $this->account();
        auth()->logout();

        $this->actingAs($this->owner)->post("/staff/{$u->id}/deactivate")->assertSessionHasNoErrors();
        auth()->logout();

        $this->post('/login', ['phone' => '0922334455', 'password' => 'Tempo1234'])->assertSessionHasErrors('phone');
        $this->assertGuest();
        $this->assertSame('inactive', $u->fresh()->status);
    }

    public function test_switching_off_signs_out_someone_who_is_already_signed_in(): void
    {
        $u = $this->makeUser('teacher', $this->branchA->id);
        $before = $u->fresh()->remember_token;

        $this->actingAs($this->owner)->post("/staff/{$u->id}/deactivate");

        $this->assertNotSame($before, $u->fresh()->remember_token, 'remember-me cookies stop working');
        $this->actingAs($u->fresh())->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_switching_off_deletes_their_open_database_sessions(): void
    {
        config(['session.driver' => 'database', 'session.table' => 'sessions']);
        $u = $this->makeUser('teacher', $this->branchA->id);
        $other = $this->makeUser('teacher', $this->branchA->id);
        foreach ([$u, $u, $other] as $i => $who) {
            \DB::table('sessions')->insert(['id' => "s{$i}", 'user_id' => $who->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'x', 'payload' => '', 'last_activity' => time()]);
        }

        $this->actingAs($this->owner)->post("/staff/{$u->id}/deactivate");

        $this->assertSame(0, \DB::table('sessions')->where('user_id', $u->id)->count());
        $this->assertSame(1, \DB::table('sessions')->where('user_id', $other->id)->count(), 'nobody else is signed out');
    }

    public function test_nothing_is_deleted_when_an_account_is_switched_off(): void
    {
        $teacher = $this->makeUser('teacher', $this->branchA->id);
        $enrollment = Enrollment::where('student_id', $this->studentA->id)->firstOrFail();
        $attendance = AttendanceRecord::create(['enrollment_id' => $enrollment->id, 'date' => '2026-09-10', 'status' => 'present', 'marked_by' => $teacher->id]);
        $invoice = Invoice::create(['student_id' => $this->studentA->id, 'academic_year_id' => $this->year->id, 'invoice_number' => 'INV-L1', 'total_amount' => 100, 'status' => 'pending', 'frequency' => 'annual', 'period_start' => '2026-09-01']);
        $payment = Payment::create(['invoice_id' => $invoice->id, 'amount' => 50, 'method' => 'cash', 'status' => 'verified', 'submitted_by' => $teacher->id, 'verified_by' => $teacher->id, 'verified_at' => now(), 'receipt_number' => 'R-L1']);
        $this->sectionA->update(['class_teacher_id' => $teacher->id]);
        AuditLog::record($teacher, 'student.updated', $this->studentA, ['x' => 1], ['x' => 2]);
        $logs = AuditLog::where('user_id', $teacher->id)->count();
        $this->assertGreaterThan(0, $logs);

        $this->actingAs($this->owner)->post("/staff/{$teacher->id}/deactivate")->assertSessionHasNoErrors();

        $this->assertNotNull(User::find($teacher->id), 'the user row is still there');
        $this->assertSame($teacher->id, $attendance->fresh()->marked_by);
        $this->assertSame($teacher->id, $payment->fresh()->submitted_by);
        $this->assertSame($teacher->id, $payment->fresh()->verified_by);
        $this->assertSame($teacher->id, $this->sectionA->fresh()->class_teacher_id);
        $this->assertSame($logs, AuditLog::where('user_id', $teacher->id)->count(), 'their audit trail is untouched');
    }

    public function test_the_account_stays_in_the_staff_list_and_shows_as_switched_off(): void
    {
        $u = $this->makeUser('teacher', $this->branchA->id);
        $this->actingAs($this->owner)->post("/staff/{$u->id}/deactivate");

        $row = collect($this->props($this->actingAs($this->owner)->get('/staff')->assertOk())['staff'])->firstWhere('id', $u->id);
        $this->assertSame('inactive', $row['status']);
    }

    public function test_you_cannot_switch_off_your_own_account(): void
    {
        $admin = $this->admin(['manage-staff']);

        $this->actingAs($admin)->post("/staff/{$admin->id}/deactivate")->assertSessionHasErrors('status');
        $this->assertSame('active', $admin->fresh()->status);

        $this->actingAs($admin)->patch("/staff/{$admin->id}", ['title' => 'x', 'status' => 'inactive', 'permissions' => ['manage-staff']])->assertSessionHasErrors('status');
        $this->assertSame('active', $admin->fresh()->status);
    }

    public function test_switching_off_twice_changes_nothing_and_says_so(): void
    {
        $u = $this->makeUser('teacher', $this->branchA->id);
        $this->actingAs($this->owner)->post("/staff/{$u->id}/deactivate");
        $count = SecurityEvent::where('event', 'account_disabled')->count();

        $this->actingAs($this->owner)->post("/staff/{$u->id}/deactivate")->assertSessionHas('success');

        $this->assertSame($count, SecurityEvent::where('event', 'account_disabled')->count());
    }

    public function test_switching_off_is_recorded_in_the_security_log(): void
    {
        $u = $this->makeUser('teacher', $this->branchA->id);
        $this->actingAs($this->owner)->post("/staff/{$u->id}/deactivate");

        $event = SecurityEvent::where('event', 'account_disabled')->latest('id')->firstOrFail();
        $this->assertSame($u->id, $event->user_id);
    }

    public function test_switching_off_through_the_edit_form_ends_sessions_too(): void
    {
        $u = $this->makeUser('teacher', $this->branchA->id);
        $before = $u->fresh()->remember_token;

        $this->actingAs($this->owner)->patch("/staff/{$u->id}", ['title' => 'T', 'status' => 'inactive'])->assertSessionHasNoErrors();

        $this->assertNotSame($before, $u->fresh()->remember_token);
    }

    // ------------------------------------------------------------------ switching it on again

    public function test_a_reactivated_account_signs_in_with_its_existing_password(): void
    {
        $this->create(['temporary_password' => 'Tempo1234']);
        $u = $this->account();
        auth()->logout();

        $this->actingAs($this->owner)->post("/staff/{$u->id}/deactivate");
        $this->actingAs($this->owner)->post("/staff/{$u->id}/reactivate")->assertSessionHasNoErrors();
        auth()->logout();

        $this->assertSame('active', $u->fresh()->status);
        $this->post('/login', ['phone' => '0922334455', 'password' => 'Tempo1234'])->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($u);
    }

    public function test_reactivating_clears_any_sign_in_waiting_time(): void
    {
        $u = $this->makeUser('teacher', $this->branchA->id, []);
        $u->update(['login_phone' => '+251977001122', 'password' => 'Right-Pass1']);
        for ($i = 0; $i < 4; $i++) {
            $this->post('/login', ['phone' => '0977001122', 'password' => 'wrong']);
        }
        auth()->logout();
        $this->actingAs($this->owner)->post("/staff/{$u->id}/deactivate");
        $this->actingAs($this->owner)->post("/staff/{$u->id}/reactivate");
        auth()->logout();

        $this->post('/login', ['phone' => '0977001122', 'password' => 'Right-Pass1'])->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($u);
    }

    public function test_reactivating_an_active_account_changes_nothing(): void
    {
        $u = $this->makeUser('teacher', $this->branchA->id);
        $this->actingAs($this->owner)->post("/staff/{$u->id}/reactivate")->assertSessionHas('success');
        $this->assertSame(0, SecurityEvent::where('event', 'account_enabled')->count());
    }

    public function test_reactivating_is_recorded_and_respects_scope(): void
    {
        $u = $this->makeUser('teacher', $this->branchB->id);
        $u->update(['status' => 'inactive']);

        $this->actingAs($this->admin(['manage-staff']))->post("/staff/{$u->id}/reactivate")->assertForbidden();
        $this->assertSame('inactive', $u->fresh()->status);

        $this->actingAs($this->owner)->post("/staff/{$u->id}/reactivate");
        $this->assertSame(1, SecurityEvent::where('event', 'account_enabled')->count());
    }

    // ------------------------------------------------------------------ resetting a password

    public function test_a_reset_gives_a_new_temporary_password_shown_once_and_forces_a_change(): void
    {
        $this->create(['temporary_password' => 'Tempo1234']);
        $u = $this->account();
        $u->update(['must_change_password' => false, 'password' => 'Changed-Own1']);

        $this->actingAs($this->owner)->post("/staff/{$u->id}/password-reset")->assertSessionHasNoErrors();

        $c = session('credentials');
        $this->assertTrue($c['reset']);
        $fresh = $u->fresh();
        $this->assertTrue((bool) $fresh->must_change_password);
        $this->assertTrue(\Hash::check($this->temporary(), $fresh->password));
        $this->assertFalse(\Hash::check('Changed-Own1', $fresh->password), 'their old password stops working');
    }

    public function test_a_switched_off_account_can_still_be_reset_but_still_cannot_sign_in(): void
    {
        $u = $this->makeUser('teacher', $this->branchA->id);
        $u->update(['login_phone' => '+251977445566']);
        $this->actingAs($this->owner)->post("/staff/{$u->id}/deactivate");
        $this->actingAs($this->owner)->post("/staff/{$u->id}/password-reset");
        $temporary = $this->temporary();
        auth()->logout();

        $this->post('/login', ['phone' => '0977445566', 'password' => $temporary])->assertSessionHasErrors('phone');
        $this->assertGuest();
    }

    // ------------------------------------------------------------------ changing account details

    public function test_the_name_and_phone_can_be_changed_and_a_changed_phone_is_the_new_login(): void
    {
        $this->create(['temporary_password' => 'Tempo1234']);
        $u = $this->account();

        $this->actingAs($this->owner)->patch("/staff/{$u->id}", ['name' => 'Tigist A. Alemu', 'title' => 'Head of Maths', 'phone' => '0933 11 22 33', 'status' => 'active'])->assertSessionHasNoErrors();

        $u = $u->fresh();
        $this->assertSame('Tigist A. Alemu', $u->name);
        $this->assertSame('+251933112233', $u->login_phone);
        auth()->logout();
        $this->post('/login', ['phone' => '0933112233', 'password' => 'Tempo1234'])->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($u);
    }

    public function test_a_blank_name_is_refused_but_leaving_it_out_keeps_the_name(): void
    {
        $u = $this->makeUser('teacher', $this->branchA->id);
        $name = $u->name;

        $this->actingAs($this->owner)->patch("/staff/{$u->id}", ['name' => '', 'title' => 'T', 'status' => 'active'])->assertSessionHasErrors('name');
        $this->actingAs($this->owner)->patch("/staff/{$u->id}", ['title' => 'T', 'status' => 'active'])->assertSessionHasNoErrors();
        $this->assertSame($name, $u->fresh()->name);
    }

    // ------------------------------------------------------------------ passwords are never exposed later

    public function test_no_page_shows_a_password_after_the_hand_over(): void
    {
        $this->create(['temporary_password' => 'Tempo1234']);
        $u = $this->account();
        $this->actingAs($this->owner)->get('/staff/create')->assertOk();   // the one page load that shows the hand-over

        foreach (['/staff', '/staff/create'] as $url) {
            $body = $this->actingAs($this->owner)->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('Tempo1234', $body);
            $this->assertStringNotContainsString($u->fresh()->password, $body, 'not even the hash');
        }

        $row = collect($this->props($this->actingAs($this->owner)->get('/staff')))->get('staff');
        foreach ($row as $person) {
            $this->assertArrayNotHasKey('password', $person);
            $this->assertArrayNotHasKey('remember_token', $person);
        }
    }

    public function test_no_password_reaches_the_audit_or_security_logs(): void
    {
        $this->create(['temporary_password' => 'Tempo1234']);
        $u = $this->account();
        $this->actingAs($this->owner)->post("/staff/{$u->id}/password-reset");
        $reset = $this->temporary();
        $this->actingAs($this->owner)->post("/staff/{$u->id}/deactivate");
        $this->actingAs($this->owner)->post("/staff/{$u->id}/reactivate");

        $everything = json_encode(AuditLog::all()->toArray()).json_encode(SecurityEvent::all()->toArray());
        foreach (['Tempo1234', $reset] as $secret) {
            $this->assertStringNotContainsString($secret, $everything);
        }
    }

    public function test_creation_is_audited_with_who_did_it(): void
    {
        $this->create();

        $log = AuditLog::where('action', 'staff.role_assigned')->latest('id')->firstOrFail();
        $this->assertSame($this->owner->id, $log->user_id);
        $this->assertSame('teacher', $log->after['role']);
    }
}
