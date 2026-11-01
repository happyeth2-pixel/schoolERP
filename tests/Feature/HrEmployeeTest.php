<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

/** HR module Phase A: employee profiles, departments, job titles (positions). Deliberately separate from
 * the login account (StaffController) -- these tests cover that separation (an employee may or may not have
 * an account, linking/unlinking is its own audited action), branch scoping, deletion guards on the two
 * set-up tables, and the backfill command that catches up existing staff accounts. */
class HrEmployeeTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchool();
    }

    private function hrManager(?int $branchId = null): User
    {
        return $this->makeUser('branch_admin', $branchId, ['employee-view', 'employee-manage']);
    }

    public function test_an_employee_can_be_created_and_gets_an_auto_generated_number(): void
    {
        $manager = $this->hrManager();

        $response = $this->actingAs($manager)->post('/employees', [
            'full_name' => 'Hiwot Alemu', 'phone' => '0911000001', 'hire_date' => '2026-01-15',
            'employment_status' => 'active', 'employment_type' => 'full_time',
        ]);

        $employee = Employee::firstOrFail();
        $response->assertRedirect(route('employees.show', $employee));
        $this->assertStringStartsWith('EMP-', $employee->employee_number);
        $this->assertSame('Hiwot Alemu', $employee->full_name);
        $this->assertNull($employee->user_id);
    }

    public function test_linking_an_employee_to_an_account_at_creation_is_recorded_in_the_audit_trail(): void
    {
        $manager = $this->hrManager();
        $teacher = $this->makeUser('teacher', $this->branchA->id, []);

        $this->actingAs($manager)->post('/employees', [
            'full_name' => $teacher->name, 'phone' => '0911000002', 'hire_date' => '2026-01-15',
            'employment_status' => 'active', 'employment_type' => 'full_time', 'user_id' => $teacher->id,
        ]);

        $employee = Employee::firstOrFail();
        $this->assertSame($teacher->id, $employee->user_id);
        $this->assertTrue(AuditLog::where('auditable_type', Employee::class)->where('auditable_id', $employee->id)->exists());
    }

    public function test_an_account_cannot_be_linked_to_two_different_employees(): void
    {
        $manager = $this->hrManager();
        $teacher = $this->makeUser('teacher', $this->branchA->id, []);
        Employee::create(['school_id' => $this->school->id, 'user_id' => $teacher->id, 'employee_number' => 'EMP-2026-000001', 'full_name' => $teacher->name, 'phone' => '0911000003', 'hire_date' => '2026-01-01', 'employment_status' => 'active', 'employment_type' => 'full_time', 'created_by' => $manager->id]);

        $this->actingAs($manager)->post('/employees', [
            'full_name' => 'Someone Else', 'phone' => '0911000004', 'hire_date' => '2026-01-15',
            'employment_status' => 'active', 'employment_type' => 'full_time', 'user_id' => $teacher->id,
        ])->assertStatus(422);

        $this->assertSame(1, Employee::count());
    }

    public function test_unlinking_an_account_from_an_employee_is_audited_and_the_account_itself_is_unaffected(): void
    {
        $manager = $this->hrManager();
        $teacher = $this->makeUser('teacher', $this->branchA->id, ['mark-attendance']);
        $employee = Employee::create(['school_id' => $this->school->id, 'branch_id' => $this->branchA->id, 'user_id' => $teacher->id, 'employee_number' => 'EMP-2026-000001', 'full_name' => $teacher->name, 'phone' => '0911000005', 'hire_date' => '2026-01-01', 'employment_status' => 'active', 'employment_type' => 'full_time', 'created_by' => $manager->id]);

        $this->actingAs($manager)->put("/employees/{$employee->id}", [
            'full_name' => $employee->full_name, 'phone' => $employee->phone, 'hire_date' => '2026-01-01',
            'employment_status' => 'active', 'employment_type' => 'full_time', 'user_id' => '',
        ])->assertRedirect(route('employees.show', $employee));

        $employee->refresh();
        $this->assertNull($employee->user_id);
        $teacher->refresh();
        $this->assertTrue($teacher->can('mark-attendance'), 'unlinking the HR record must never touch the account\'s own permissions');
        $this->assertTrue(AuditLog::where('auditable_type', Employee::class)->where('action', 'employee.account_unlinked')->exists());
    }

    public function test_a_branch_admin_only_sees_employees_in_their_own_branch(): void
    {
        $managerA = $this->hrManager($this->branchA->id);
        $ownerWide = $this->owner;

        Employee::create(['school_id' => $this->school->id, 'branch_id' => $this->branchA->id, 'employee_number' => 'EMP-2026-000001', 'full_name' => 'Branch A Person', 'phone' => '1', 'hire_date' => '2026-01-01', 'employment_status' => 'active', 'employment_type' => 'full_time', 'created_by' => $ownerWide->id]);
        $employeeB = Employee::create(['school_id' => $this->school->id, 'branch_id' => $this->branchB->id, 'employee_number' => 'EMP-2026-000002', 'full_name' => 'Branch B Person', 'phone' => '2', 'hire_date' => '2026-01-01', 'employment_status' => 'active', 'employment_type' => 'full_time', 'created_by' => $ownerWide->id]);

        $response = $this->actingAs($managerA)->get('/employees');
        $names = collect($this->props($response)['employees'])->pluck('full_name');
        $this->assertTrue($names->contains('Branch A Person'));
        $this->assertFalse($names->contains('Branch B Person'));

        $this->actingAs($managerA)->get("/employees/{$employeeB->id}")->assertForbidden();
    }

    public function test_someone_without_any_hr_permission_cannot_view_or_manage_employees(): void
    {
        $bystander = $this->makeUser('teacher', $this->branchA->id, []);
        $this->actingAs($bystander)->get('/employees')->assertForbidden();
        $this->actingAs($bystander)->get('/employees/new')->assertForbidden();
        $this->actingAs($bystander)->post('/employees', [])->assertForbidden();
    }

    public function test_a_department_cannot_be_removed_while_it_has_employees_or_positions(): void
    {
        $manager = $this->hrManager();
        $department = Department::create(['school_id' => $this->school->id, 'name' => 'Finance']);
        Employee::create(['school_id' => $this->school->id, 'department_id' => $department->id, 'employee_number' => 'EMP-2026-000001', 'full_name' => 'X', 'phone' => '1', 'hire_date' => '2026-01-01', 'employment_status' => 'active', 'employment_type' => 'full_time', 'created_by' => $manager->id]);

        $this->actingAs($manager)->delete("/departments/{$department->id}")->assertSessionHasErrors();
        $this->assertNotNull($department->fresh());
    }

    public function test_a_department_with_nothing_depending_on_it_can_be_removed(): void
    {
        $manager = $this->hrManager();
        $department = Department::create(['school_id' => $this->school->id, 'name' => 'Temp']);

        $this->actingAs($manager)->delete("/departments/{$department->id}")->assertSessionHasNoErrors();
        $this->assertNull(Department::find($department->id));
    }

    public function test_a_job_title_cannot_be_removed_while_an_employee_holds_it(): void
    {
        $manager = $this->hrManager();
        $position = Position::create(['school_id' => $this->school->id, 'title' => 'Senior Accountant']);
        Employee::create(['school_id' => $this->school->id, 'position_id' => $position->id, 'employee_number' => 'EMP-2026-000001', 'full_name' => 'X', 'phone' => '1', 'hire_date' => '2026-01-01', 'employment_status' => 'active', 'employment_type' => 'full_time', 'created_by' => $manager->id]);

        $this->actingAs($manager)->delete("/positions/{$position->id}")->assertSessionHasErrors();
    }

    public function test_job_title_is_a_real_record_distinct_from_the_accountants_system_role(): void
    {
        $manager = $this->hrManager();
        $accountant = $this->makeUser('accountant', $this->branchA->id, ['manage-invoices']);

        $this->actingAs($manager)->post('/employees', [
            'full_name' => $accountant->name, 'phone' => '0911000006', 'hire_date' => '2026-01-01',
            'employment_status' => 'active', 'employment_type' => 'full_time', 'user_id' => $accountant->id,
        ]);
        $this->actingAs($manager)->post('/positions', ['title' => 'Senior Accountant']);
        $position = Position::firstOrFail();
        $employee = Employee::firstOrFail();

        $this->actingAs($manager)->put("/employees/{$employee->id}", [
            'full_name' => $employee->full_name, 'phone' => $employee->phone, 'hire_date' => '2026-01-01',
            'employment_status' => 'active', 'employment_type' => 'full_time', 'position_id' => $position->id, 'user_id' => $accountant->id,
        ]);

        $employee->refresh();
        $this->assertSame('Senior Accountant', $employee->position->title);
        $this->assertTrue($accountant->fresh()->hasRole('accountant'), 'the job title is independent of the Spatie system role');
    }

    // ---------------------------------------------------------------- backfill command

    public function test_backfill_creates_an_employee_for_every_staff_account_without_one(): void
    {
        $teacher = $this->makeUser('teacher', $this->branchA->id, []);
        $accountant = $this->makeUser('accountant', $this->branchB->id, []);
        // a parent account must never get an employee record
        $this->makeParent($this->studentA);

        Artisan::call('hr:backfill-employees', ['--school' => $this->school->id]);

        $this->assertNotNull(Employee::where('user_id', $teacher->id)->first());
        $this->assertNotNull(Employee::where('user_id', $accountant->id)->first());
        $this->assertNotNull(Employee::where('user_id', $this->owner->id)->first());
        $this->assertSame(3, Employee::count(), 'exactly owner + teacher + accountant, never the parent');
    }

    public function test_backfill_is_idempotent(): void
    {
        $this->makeUser('teacher', $this->branchA->id, []);

        Artisan::call('hr:backfill-employees', ['--school' => $this->school->id]);
        $countAfterFirst = Employee::count();

        Artisan::call('hr:backfill-employees', ['--school' => $this->school->id]);
        $this->assertSame($countAfterFirst, Employee::count(), 're-running the backfill must not create duplicate employee records');
    }

    public function test_backfill_dry_run_creates_nothing(): void
    {
        $this->makeUser('teacher', $this->branchA->id, []);

        Artisan::call('hr:backfill-employees', ['--school' => $this->school->id, '--dry-run' => true]);

        $this->assertSame(0, Employee::count());
    }

    public function test_an_employee_can_be_registered_with_an_age_instead_of_a_birthdate(): void
    {
        \Illuminate\Support\Carbon::setTestNow('2026-10-15 09:00:00');
        $manager = $this->hrManager();

        $this->actingAs($manager)->post('/employees', [
            'full_name' => 'Tigist Alemu', 'phone' => '0911000002', 'hire_date' => '2026-01-15', 'age' => 30,
            'employment_status' => 'active', 'employment_type' => 'full_time',
        ])->assertSessionHasNoErrors();

        $employee = Employee::firstOrFail();
        $this->assertSame(30, $employee->age());
        $this->assertNotNull($employee->date_of_birth);

        $this->actingAs($manager)->put("/employees/{$employee->id}", [
            'full_name' => 'Tigist Alemu', 'phone' => '0911000002', 'hire_date' => '2026-01-15', 'age' => 31,
            'employment_status' => 'active', 'employment_type' => 'full_time',
        ])->assertSessionHasNoErrors();
        $this->assertSame(31, $employee->fresh()->age());
    }

    public function test_the_edit_page_is_given_an_age_not_a_raw_birthdate(): void
    {
        \Illuminate\Support\Carbon::setTestNow('2026-10-15 09:00:00');
        $manager = $this->hrManager();

        $this->actingAs($manager)->post('/employees', [
            'full_name' => 'Age Edit', 'phone' => '0911000003', 'hire_date' => '2026-01-15', 'age' => 25,
            'employment_status' => 'active', 'employment_type' => 'full_time',
        ]);
        $employee = Employee::firstOrFail();

        $page = $this->props($this->actingAs($manager)->get("/employees/{$employee->id}/edit")->assertOk());
        $this->assertSame(25, $page['employee']['age']);
        $this->assertArrayNotHasKey('date_of_birth', $page['employee']);
    }
}
