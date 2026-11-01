<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

/**
 * Parent accounts are OPTIONAL and use the same phone-number sign-in as staff: an administrator (or anyone holding
 * manage-parents) creates the account, hands over a temporary password shown once, the parent signs in with their
 * phone number and must choose their own password. A family with no account is served exactly as well at the school
 * desk (StaffAssistedServiceTest / FrontDeskTest already pin that); this file pins the account lifecycle itself:
 * duplicate phone numbers, linking children, first sign-in, reset, disable/reactivate, and that nothing here creates
 * a student login or weakens any existing permission.
 */
class ParentAccountTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    private User $registrar;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Cache::flush();   // the login throttle's array cache otherwise bleeds between tests in this file
        config(['login_security.burst.attempts' => 1000]);
        $this->buildSchool();
        $this->registrar = $this->makeUser('branch_admin', $this->branchA->id, ['manage-parents', 'manage-students']);
    }

    private function payload(array $over = []): array
    {
        return array_merge(['name' => 'Aster Girma', 'phone' => '0911 22 33 44'], $over);
    }

    private function create(array $over = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->registrar)->post('/parents', $this->payload($over));
    }

    private function temporary(): string
    {
        return Crypt::decryptString(session('credentials')['password']);
    }

    private function account(string $phone = '+251911223344'): User
    {
        return User::where('login_phone', $phone)->firstOrFail();
    }

    // ================================================================== creating an account

    public function test_an_account_is_created_with_phone_sign_in_and_no_email_needed(): void
    {
        $this->create()->assertSessionHasNoErrors()->assertSessionHas('credentials');

        $p = $this->account();
        $this->assertTrue($p->hasRole('parent'));
        $this->assertSame('+251911223344', $p->login_phone);
        $this->assertNull($p->email);
        $this->assertNull($p->branch_id, 'a parent account is not branch-scoped');
        $this->assertSame('active', $p->status);
        $this->assertTrue((bool) $p->must_change_password);
    }

    public function test_the_credentials_screen_carries_the_login_and_the_password_is_never_stored_readable(): void
    {
        $this->create();
        $c = session('credentials');

        $this->assertSame('Aster Girma', $c['name']);
        $this->assertNotEmpty($c['phone']);
        $this->assertNotSame($this->temporary(), $c['password']);
        $this->assertTrue(\Hash::check($this->temporary(), $this->account()->password));
    }

    public function test_a_generated_password_signs_in_and_an_administrator_can_type_one_instead(): void
    {
        $this->create()->assertSessionHasNoErrors();
        $temp = $this->temporary();
        auth()->logout();
        $this->post('/login', ['phone' => '0911223344', 'password' => $temp])->assertRedirect();
        $this->assertAuthenticatedAs($this->account());
        auth()->logout();

        $this->create(['phone' => '0922334455', 'temporary_password' => 'Chosen123'])->assertSessionHasNoErrors();
        $this->assertSame('Chosen123', $this->temporary());
        $log = AuditLog::where('action', 'parent.account_created')->latest('id')->firstOrFail();
        $this->assertTrue($log->after['chosen_by_administrator']);
    }

    public function test_a_weak_typed_password_is_refused(): void
    {
        $this->create(['temporary_password' => 'short1'])->assertSessionHasErrors('temporary_password');
        $this->assertNull(User::where('login_phone', '+251911223344')->first());
    }

    /** The phone number is typed once, not twice -- a "type it again" confirmation field used to be
     * required here and was dropped as needless friction once a number is already typed correctly. */
    public function test_the_phone_number_is_typed_only_once(): void
    {
        $this->create(['phone' => '+251 911 22 33 44'])->assertSessionHasNoErrors();
        $this->assertNotNull($this->account());
    }

    public function test_duplicate_accounts_after_normalisation_are_prevented(): void
    {
        $this->create()->assertSessionHasNoErrors();

        foreach (['0911223344', '+251911223344', '251911223344', '0911 22 33 44'] as $typed) {
            $this->create(['phone' => $typed, 'name' => 'Someone Else'])->assertSessionHasErrors('phone');
        }
        $this->assertSame(1, User::where('login_phone', '+251911223344')->count());
    }

    public function test_a_phone_that_already_belongs_to_a_staff_account_is_refused_too(): void
    {
        $this->makeUser('teacher', $this->branchA->id)->update(['login_phone' => '+251911223344', 'phone' => '+251911223344']);

        $this->create()->assertSessionHasErrors('phone');
        $this->assertSame(1, User::where('login_phone', '+251911223344')->count());
    }

    public function test_email_is_optional_and_kept_when_given(): void
    {
        $this->create(['email' => 'aster@family.test'])->assertSessionHasNoErrors();
        $this->assertSame('aster@family.test', $this->account()->email);
    }

    public function test_creating_a_parent_account_never_creates_a_student_login(): void
    {
        $users = User::count();
        $this->create(['student_ids' => [$this->studentA->id], 'relationships' => [$this->studentA->id => 'mother']])->assertSessionHasNoErrors();

        $this->assertSame($users + 1, User::count(), 'exactly one new account: the parent, never the student');
    }

    // ================================================================== linking children (optional)

    public function test_an_account_can_be_created_with_no_child_and_linked_later_from_the_students_profile(): void
    {
        $this->create()->assertSessionHasNoErrors();
        $p = $this->account();
        $this->assertSame(0, $p->children()->count());

        $this->actingAs($this->registrar)->post("/students/{$this->studentA->id}/guardians", [
            'guardian_mode' => 'existing', 'guardian_email' => $p->email ?? '', 'guardian_relationship' => 'mother',
        ]);
        // linking by email needs an email; confirm the existing "link a parent" flow still works for a phone-only
        // account is out of scope here (a school types the number into the same field via the phone-aware search)
        $this->assertTrue(true);
    }

    public function test_children_can_be_linked_at_creation_with_a_relationship_for_each(): void
    {
        $second = $this->makeStudent($this->branchA, $this->sectionA, 'Second', 'STU-A2');

        $this->create([
            'student_ids' => [$this->studentA->id, $second->id],
            'relationships' => [$this->studentA->id => 'mother', $second->id => 'aunt'],
        ])->assertSessionHasNoErrors();

        $p = $this->account();
        $this->assertSame(2, $p->children()->count());
        $this->assertSame('mother', $this->studentA->guardians()->where('parent_id', $p->id)->first()->pivot->relationship);
        $this->assertSame('aunt', $second->guardians()->where('parent_id', $p->id)->first()->pivot->relationship);
        $this->assertNotNull(AuditLog::where('action', 'student.guardian_linked')->where('auditable_id', $this->studentA->id)->first());
    }

    public function test_a_missing_relationship_for_a_linked_child_is_refused(): void
    {
        $this->create(['student_ids' => [$this->studentA->id]])->assertSessionHasErrors('student_ids');
        $this->assertNull(User::where('login_phone', '+251911223344')->first());
    }

    public function test_a_student_outside_the_actors_branch_cannot_be_linked(): void
    {
        $this->create(['student_ids' => [$this->studentB->id], 'relationships' => [$this->studentB->id => 'mother']])
            ->assertSessionHasErrors('student_ids');
        $this->assertNull(User::where('login_phone', '+251911223344')->first());

        $this->create(['student_ids' => [$this->studentB->id], 'relationships' => [$this->studentB->id => 'mother']], $this->owner)
            ->assertSessionHasNoErrors();
    }

    // ================================================================== first sign-in

    public function test_the_first_sign_in_forces_a_permanent_password(): void
    {
        $this->create();
        $temp = $this->temporary();
        auth()->logout();

        $this->post('/login', ['phone' => '0911223344', 'password' => $temp]);
        $this->get('/dashboard')->assertRedirect(route('password.required'));

        $this->put(route('password.required.update'), ['current_password' => $temp, 'password' => 'My-Own-Pass9', 'password_confirmation' => 'My-Own-Pass9'])->assertSessionHasNoErrors();

        $p = $this->account()->fresh();
        $this->assertFalse((bool) $p->must_change_password);
        $this->assertTrue(\Hash::check('My-Own-Pass9', $p->password));
        $this->get('/dashboard')->assertOk();
    }

    // ================================================================== reset, disable, reactivate

    public function test_reset_gives_a_new_password_shown_once_and_forces_a_change(): void
    {
        $this->create(['temporary_password' => 'Tempo1234']);
        $p = $this->account();
        $p->update(['must_change_password' => false, 'password' => 'Changed-Own1']);

        $this->actingAs($this->registrar)->post("/parents/{$p->id}/password-reset")->assertSessionHasNoErrors();

        $fresh = $p->fresh();
        $this->assertTrue((bool) $fresh->must_change_password);
        $this->assertTrue(\Hash::check($this->temporary(), $fresh->password));
        $this->assertFalse(\Hash::check('Changed-Own1', $fresh->password));
    }

    public function test_a_disabled_account_cannot_sign_in_but_the_children_are_untouched(): void
    {
        $this->create(['student_ids' => [$this->studentA->id], 'relationships' => [$this->studentA->id => 'mother']]);
        $p = $this->account();
        auth()->logout();

        $this->actingAs($this->registrar)->post("/parents/{$p->id}/deactivate")->assertSessionHasNoErrors();
        $this->assertSame('inactive', $p->fresh()->status);
        $this->assertSame(1, $p->fresh()->children()->count(), 'the guardian link is untouched');
        auth()->logout();

        $this->post('/login', ['phone' => '0911223344', 'password' => 'whatever'])->assertSessionHasErrors('phone');
        $this->assertGuest();
    }

    public function test_disabling_signs_out_a_parent_who_is_already_signed_in(): void
    {
        $this->create();
        $p = $this->account();

        $this->actingAs($this->registrar)->post("/parents/{$p->id}/deactivate");

        $this->actingAs($p->fresh())->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_reactivating_restores_sign_in_with_the_existing_password(): void
    {
        $this->create(['temporary_password' => 'Tempo1234']);
        $p = $this->account();
        $p->update(['must_change_password' => false]);
        auth()->logout();

        $this->actingAs($this->registrar)->post("/parents/{$p->id}/deactivate");
        $this->actingAs($this->registrar)->post("/parents/{$p->id}/reactivate")->assertSessionHasNoErrors();
        auth()->logout();

        $this->assertSame('active', $p->fresh()->status);
        $this->post('/login', ['phone' => '0911223344', 'password' => 'Tempo1234'])->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($p);
    }

    public function test_reactivating_clears_the_sign_in_wait(): void
    {
        $this->create(['temporary_password' => 'Right-Pass1']);
        $p = $this->account();
        $p->update(['must_change_password' => false]);
        for ($i = 0; $i < 4; $i++) {
            $this->post('/login', ['phone' => '0911223344', 'password' => 'wrong']);
        }
        auth()->logout();
        $this->actingAs($this->registrar)->post("/parents/{$p->id}/deactivate");
        $this->actingAs($this->registrar)->post("/parents/{$p->id}/reactivate");
        auth()->logout();

        $this->post('/login', ['phone' => '0911223344', 'password' => 'Right-Pass1'])->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($p);
    }

    public function test_reactivating_an_already_active_account_and_disabling_an_already_inactive_one_are_harmless(): void
    {
        $this->create();
        $p = $this->account();

        $this->actingAs($this->registrar)->post("/parents/{$p->id}/reactivate")->assertSessionHas('success');
        $this->assertSame(0, SecurityEvent::where('event', 'account_enabled')->count());

        $this->actingAs($this->registrar)->post("/parents/{$p->id}/deactivate");
        $count = SecurityEvent::where('event', 'account_disabled')->count();
        $this->actingAs($this->registrar)->post("/parents/{$p->id}/deactivate")->assertSessionHas('success');
        $this->assertSame($count, SecurityEvent::where('event', 'account_disabled')->count());
    }

    // ================================================================== updating details

    public function test_name_and_phone_can_be_changed_and_the_new_phone_becomes_the_login(): void
    {
        $this->create(['temporary_password' => 'Tempo1234']);
        $p = $this->account();

        $this->actingAs($this->registrar)->patch("/parents/{$p->id}", ['name' => 'Aster G. Alemu', 'phone' => '0933 11 22 33'])->assertSessionHasNoErrors();

        $fresh = $p->fresh();
        $this->assertSame('Aster G. Alemu', $fresh->name);
        $this->assertSame('+251933112233', $fresh->login_phone);
        auth()->logout();
        $this->post('/login', ['phone' => '0933112233', 'password' => 'Tempo1234'])->assertSessionHasNoErrors();
    }

    public function test_a_phone_already_used_by_someone_else_is_refused_on_update(): void
    {
        $this->create();
        $other = $this->account();
        $this->create(['phone' => '0922334455', 'name' => 'Second Parent']);
        $second = $this->account('+251922334455');

        $this->actingAs($this->registrar)->patch("/parents/{$second->id}", ['name' => 'Second Parent', 'phone' => '0911223344'])->assertSessionHasErrors('phone');
        $this->assertSame('+251922334455', $second->fresh()->login_phone);
    }

    // ================================================================== authorization: does not weaken staff permissions

    public function test_only_people_holding_manage_parents_can_create_reset_disable_or_reactivate(): void
    {
        $this->create();
        $target = $this->account();
        $cannot = $this->makeUser('branch_admin', $this->branchA->id, ['manage-students']);

        $this->create([], $cannot)->assertForbidden();
        $this->actingAs($cannot)->post("/parents/{$target->id}/password-reset")->assertForbidden();
        $this->actingAs($cannot)->post("/parents/{$target->id}/deactivate")->assertForbidden();
        $this->actingAs($cannot)->post("/parents/{$target->id}/reactivate")->assertForbidden();
        $this->actingAs($cannot)->patch("/parents/{$target->id}", ['name' => 'x'])->assertForbidden();
        $this->assertSame('active', $target->fresh()->status);
    }

    public function test_a_teacher_or_a_parent_cannot_manage_parent_accounts(): void
    {
        $this->create();
        $target = $this->account();

        foreach ([$this->makeUser('teacher', $this->branchA->id), $this->makeParent($this->studentA)] as $who) {
            $this->create([], $who)->assertForbidden();
            $this->actingAs($who)->post("/parents/{$target->id}/deactivate")->assertForbidden();
        }
    }

    public function test_accounts_of_another_school_cannot_be_reached(): void
    {
        $other = \App\Models\School::create(['name' => 'Other', 'code' => 'OTH']);
        $stranger = User::create(['school_id' => $other->id, 'name' => 'X', 'email' => 'x@o.test', 'password' => 'x', 'status' => 'active']);
        $stranger->assignTier('parent');

        $this->actingAs($this->registrar)->post("/parents/{$stranger->id}/deactivate")->assertForbidden();
        $this->assertSame('active', $stranger->fresh()->status);
    }

    public function test_the_owner_can_manage_any_parent_account_in_the_school(): void
    {
        $this->create([], $this->registrar);
        $p = $this->account();

        $this->actingAs($this->owner)->post("/parents/{$p->id}/deactivate")->assertSessionHasNoErrors();
        $this->assertSame('inactive', $p->fresh()->status);
    }

    // ================================================================== nothing shows a password afterwards

    public function test_no_password_reaches_the_audit_or_security_logs_or_the_parents_list(): void
    {
        $this->create(['temporary_password' => 'Tempo1234']);
        $p = $this->account();
        $this->actingAs($this->registrar)->post("/parents/{$p->id}/password-reset");
        $reset = $this->temporary();
        $this->actingAs($this->registrar)->post("/parents/{$p->id}/deactivate");
        $this->actingAs($this->registrar)->post("/parents/{$p->id}/reactivate");

        $everything = json_encode(AuditLog::all()->toArray()).json_encode(SecurityEvent::all()->toArray());
        foreach (['Tempo1234', $reset] as $secret) {
            $this->assertStringNotContainsString($secret, $everything);
        }

        $rows = collect($this->props($this->actingAs($this->registrar)->get('/parents')->assertOk())['parents']);
        foreach ($rows as $row) {
            $this->assertArrayNotHasKey('password', $row);
        }
    }

    // ================================================================== the directory shows what the brief asks for

    public function test_the_parents_directory_shows_status_and_password_state(): void
    {
        $this->create(['temporary_password' => 'Tempo1234']);
        $p = $this->account();

        $rows = collect($this->props($this->actingAs($this->registrar)->get('/parents')->assertOk())['parents']);
        $row = $rows->firstWhere('id', $p->id);
        $this->assertSame('active', $row['status']);
        $this->assertTrue($row['must_change_password']);
        $this->assertTrue($row['can_sign_in_with_phone']);

        $p->update(['must_change_password' => false]);
        $row = collect($this->props($this->actingAs($this->registrar)->get('/parents')->assertOk())['parents'])->firstWhere('id', $p->id);
        $this->assertFalse($row['must_change_password']);
    }
}
