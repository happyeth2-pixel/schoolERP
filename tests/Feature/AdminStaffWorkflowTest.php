<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

/**
 * The staff wizard's page and the endpoints behind it. The point of the redesign is words and steps; none of the
 * rules moved, so these tests pin them: who may add whom, that a job title alone grants nothing, that nobody can
 * hand out a permission they do not hold, that a branch admin stays inside their own branch, and the audit trail.
 */
class AdminStaffWorkflowTest extends TestCase
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
        $data = array_merge(['name' => 'New Person', 'email' => 'new.person@test.test', 'title' => 'Registrar', 'phone' => '0911 22 33 44', 'role' => 'branch_admin', 'branch_id' => $this->branchA->id, 'permissions' => ['manage-students', 'manage-parents']], $over);

        return $data + ['phone_confirmation' => $data['phone']];
    }

    private function admin(array $permissions): User
    {
        return $this->makeUser('branch_admin', $this->branchA->id, $permissions);
    }

    // ------------------------------------------------------------------ the wizard page

    public function test_the_wizard_page_gives_groups_defaults_and_only_what_this_person_may_give(): void
    {
        $page = json_decode(json_encode($this->props($this->actingAs($this->owner)->get('/staff/create')->assertOk())), true);

        $grouped = collect($page['permissionGroups'])->flatten()->all();
        $this->assertEqualsCanonicalizing(RolesAndPermissionsSeeder::PERMISSIONS, $grouped, 'every permission is in exactly one group');
        $this->assertSame(count($grouped), count(array_unique($grouped)));
        $this->assertEqualsCanonicalizing(['owner', 'branch_admin', 'teacher', 'accountant', 'auditor'], array_keys($page['tierDefaults']));
        $this->assertSame(RolesAndPermissionsSeeder::DEFAULT_PERMISSIONS['teacher'], $page['tierDefaults']['teacher']);
        $this->assertEqualsCanonicalizing(['owner', 'branch_admin', 'teacher', 'accountant', 'auditor'], $page['assignableRoles']);
        $this->assertCount(2, $page['branches']);

        $limited = $this->admin(['manage-staff', 'manage-students']);
        $mine = json_decode(json_encode($this->props($this->actingAs($limited)->get('/staff/create')->assertOk())), true);
        $this->assertEqualsCanonicalizing(['manage-staff', 'manage-students'], $mine['myPermissions']);
        $this->assertEqualsCanonicalizing(['branch_admin', 'teacher', 'accountant'], $mine['assignableRoles'], 'only an owner can add owners and auditors');
        $this->assertSame([$this->branchA->id], array_column($mine['branches'], 'id'), 'a branch admin sees only their own branch');
    }

    public function test_only_people_who_manage_staff_can_open_the_wizard(): void
    {
        $this->actingAs($this->makeUser('teacher', $this->branchA->id))->get('/staff/create')->assertForbidden();
        $this->actingAs($this->makeUser('accountant', $this->branchA->id))->get('/staff/create')->assertForbidden();
        $this->actingAs($this->makeUser('auditor', null))->get('/staff/create')->assertForbidden();
        $this->actingAs($this->admin(['manage-students']))->get('/staff/create')->assertForbidden();
        $this->get('/logout');
        auth()->logout();
        $this->get('/staff/create')->assertRedirect('/login');
    }

    // ------------------------------------------------------------------ creating

    public function test_adding_a_branch_admin_stores_phone_title_and_exactly_the_chosen_permissions(): void
    {
        $this->actingAs($this->owner)->post('/staff', $this->payload())->assertSessionHas('credentials')->assertSessionHasNoErrors();

        $u = User::where('email', 'new.person@test.test')->firstOrFail();
        $this->assertSame('Registrar', $u->title);
        $this->assertSame('+251911223344', $u->phone);
        $this->assertTrue($u->hasRole('branch_admin'));
        $this->assertSame($this->branchA->id, $u->branch_id);
        $this->assertEqualsCanonicalizing(['manage-students', 'manage-parents'], $u->getPermissionNames()->all());
        $this->assertSame('New Person', session('credentials')['name']);
        $this->assertTrue($u->must_change_password);
    }

    public function test_a_job_title_alone_grants_nothing(): void
    {
        $this->actingAs($this->owner)->post('/staff', $this->payload(['title' => 'Vice Principal', 'permissions' => []]));

        $u = User::where('email', 'new.person@test.test')->firstOrFail();
        $this->assertSame('Vice Principal', $u->title);
        $this->assertSame([], $u->getPermissionNames()->all());
        $this->assertFalse($u->can('manage-staff'));
        $this->assertFalse($u->can('manage-students'));
    }

    public function test_the_phone_is_required_and_email_is_optional(): void
    {
        $this->actingAs($this->owner)->post('/staff', $this->payload(['phone' => null, 'email' => 'a@test.test']))->assertSessionHasErrors('phone');
        $this->assertNull(User::where('email', 'a@test.test')->first());

        $this->actingAs($this->owner)->post('/staff', $this->payload(['phone' => 'not a number', 'email' => 'b@test.test']))->assertSessionHasErrors('phone');
        $this->assertNull(User::where('email', 'b@test.test')->first());

        $this->actingAs($this->owner)->post('/staff', $this->payload(['email' => null]))->assertSessionHasNoErrors();
        $u = User::where('phone', '+251911223344')->firstOrFail();
        $this->assertNull($u->email);
    }

    public function test_other_roles_keep_their_fixed_permissions_whatever_the_form_sends(): void
    {
        $this->actingAs($this->owner)->post('/staff', $this->payload(['role' => 'teacher', 'permissions' => ['manage-staff', 'refund-payments']]));

        $u = User::where('email', 'new.person@test.test')->firstOrFail();
        $this->assertEqualsCanonicalizing(RolesAndPermissionsSeeder::DEFAULT_PERMISSIONS['teacher'], $u->getPermissionNames()->all());
    }

    public function test_nobody_can_give_a_permission_they_do_not_hold(): void
    {
        $actor = $this->admin(['manage-staff', 'manage-students']);

        $this->actingAs($actor)->post('/staff', $this->payload(['permissions' => ['manage-students', 'refund-payments', 'manage-fee-structures']]))->assertSessionHasNoErrors();

        $u = User::where('email', 'new.person@test.test')->firstOrFail();
        $this->assertSame(['manage-students'], $u->getPermissionNames()->all());
    }

    public function test_only_an_owner_can_add_an_owner_or_an_auditor(): void
    {
        $actor = $this->admin(['manage-staff']);

        foreach (['owner', 'auditor'] as $i => $role) {
            $this->actingAs($actor)->post('/staff', $this->payload(['role' => $role, 'email' => "x{$i}@test.test"]))->assertSessionHasErrors('role');
            $this->assertNull(User::where('email', "x{$i}@test.test")->first());
        }

        $this->actingAs($this->owner)->post('/staff', $this->payload(['role' => 'auditor', 'branch_id' => $this->branchB->id, 'email' => 'aud@test.test']))->assertSessionHasNoErrors();
        $this->assertNull(User::where('email', 'aud@test.test')->first()->branch_id, 'an auditor works across every branch');
    }

    public function test_a_branch_admin_can_only_add_people_to_their_own_branch(): void
    {
        $this->actingAs($this->admin(['manage-staff']))->post('/staff', $this->payload(['branch_id' => $this->branchB->id, 'role' => 'teacher']));

        $this->assertSame($this->branchA->id, User::where('email', 'new.person@test.test')->first()->branch_id);
    }

    public function test_an_email_already_in_use_is_refused(): void
    {
        $this->actingAs($this->owner)->post('/staff', $this->payload())->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post('/staff', $this->payload(['name' => 'Someone Else']))->assertSessionHasErrors('email');

        $this->assertSame(1, User::where('email', 'new.person@test.test')->count());
    }

    public function test_adding_someone_is_written_to_the_audit_log_with_their_permissions(): void
    {
        $this->actingAs($this->owner)->post('/staff', $this->payload());

        $u = User::where('email', 'new.person@test.test')->firstOrFail();
        $log = AuditLog::where('action', 'staff.role_assigned')->where('auditable_id', $u->id)->firstOrFail();
        $this->assertSame('branch_admin', $log->after['role']);
        $this->assertEqualsCanonicalizing(['manage-students', 'manage-parents'], $log->after['permissions']);
        $this->assertNotNull(AuditLog::where('action', 'account.temporary_password_issued')->where('auditable_id', $u->id)->first());
    }

    public function test_someone_who_cannot_manage_staff_cannot_add_anyone(): void
    {
        $this->actingAs($this->admin(['manage-students']))->post('/staff', $this->payload())->assertForbidden();
        $this->assertNull(User::where('email', 'new.person@test.test')->first());
    }

    // ------------------------------------------------------------------ changing

    public function test_changing_a_person_updates_title_phone_status_and_audits_permission_changes(): void
    {
        $this->actingAs($this->owner)->post('/staff', $this->payload());
        $u = User::where('email', 'new.person@test.test')->firstOrFail();

        $this->actingAs($this->owner)->patch("/staff/{$u->id}", ['title' => 'Vice Principal', 'phone' => '0922334455', 'status' => 'inactive', 'permissions' => ['manage-students', 'manage-classes']])->assertSessionHasNoErrors();

        $u->refresh();
        $this->assertSame(['Vice Principal', '+251922334455', 'inactive'], [$u->title, $u->phone, $u->status]);
        $this->assertEqualsCanonicalizing(['manage-students', 'manage-classes'], $u->getPermissionNames()->all());
        $log = AuditLog::where('action', 'staff.permissions_changed')->where('auditable_id', $u->id)->firstOrFail();
        $this->assertSame(['manage-classes'], $log->after['added']);
        $this->assertSame(['manage-parents'], $log->after['removed']);
    }

    public function test_a_teachers_permissions_do_not_change_when_their_details_do(): void
    {
        $this->actingAs($this->owner)->post('/staff', $this->payload(['role' => 'teacher']));
        $t = User::where('email', 'new.person@test.test')->firstOrFail();
        $before = $t->getPermissionNames()->sort()->values()->all();

        $this->actingAs($this->owner)->patch("/staff/{$t->id}", ['title' => 'Head of Maths', 'status' => 'active', 'permissions' => ['manage-staff']]);

        $this->assertSame($before, $t->fresh()->getPermissionNames()->sort()->values()->all());
        $this->assertSame('Head of Maths', $t->fresh()->title);
    }

    public function test_a_branch_admin_cannot_change_someone_in_another_branch_or_an_owner(): void
    {
        $actor = $this->admin(['manage-staff', 'manage-students']);
        $east = $this->makeUser('teacher', $this->branchB->id);

        $this->actingAs($actor)->patch("/staff/{$east->id}", ['title' => 'x', 'status' => 'inactive'])->assertForbidden();
        $this->actingAs($actor)->patch("/staff/{$this->owner->id}", ['title' => 'x', 'status' => 'inactive'])->assertForbidden();
        $this->assertSame('active', $east->fresh()->status);
    }
}
