<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\User;
use App\Notifications\ContractExpiringSoon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

/** HR module Phase B: employment contracts. A contract's document is private (never on the public disk) and
 * only ever served through an authorized action, same pattern as PaymentController::proof(). A contract that
 * has ever been active stays in the employee's history forever -- only a still-draft one can be removed. */
class EmploymentContractTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    private const PLAIN_PDF = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF";

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchool();
    }

    private function hrManager(?int $branchId = null): User
    {
        return $this->makeUser('branch_admin', $branchId, ['employee-view', 'employee-manage', 'contract-view', 'contract-manage']);
    }

    private function employee(?int $branchId = null): Employee
    {
        return Employee::create([
            'school_id' => $this->school->id, 'branch_id' => $branchId, 'employee_number' => 'EMP-2026-000001',
            'full_name' => 'Hiwot Alemu', 'phone' => '0911000001', 'hire_date' => '2026-01-01',
            'employment_status' => 'active', 'employment_type' => 'full_time', 'created_by' => $this->owner->id,
        ]);
    }

    public function test_a_contract_can_be_added_with_a_document_and_the_document_can_be_viewed(): void
    {
        $manager = $this->hrManager($this->branchA->id);
        $employee = $this->employee($this->branchA->id);

        $response = $this->actingAs($manager)->post("/employees/{$employee->id}/contracts", [
            'contract_type' => 'permanent', 'start_date' => '2026-01-01', 'status' => 'active',
            'document' => UploadedFile::fake()->createWithContent('contract.pdf', self::PLAIN_PDF),
        ]);

        $contract = EmploymentContract::firstOrFail();
        $response->assertRedirect(route('employees.show', $employee));
        $this->assertNotNull($contract->document_path);
        $this->assertStringStartsWith('employment-contracts/', $contract->document_path);
        $this->assertTrue(Storage::disk('local')->exists($contract->document_path));

        $this->actingAs($manager)->get("/employment-contracts/{$contract->id}/document")->assertOk();
    }

    public function test_a_draft_contract_can_be_removed_but_an_active_one_cannot(): void
    {
        $manager = $this->hrManager();
        $employee = $this->employee();
        $draft = EmploymentContract::create(['employee_id' => $employee->id, 'contract_type' => 'fixed_term', 'start_date' => '2026-01-01', 'status' => 'draft', 'created_by' => $manager->id]);
        $active = EmploymentContract::create(['employee_id' => $employee->id, 'contract_type' => 'permanent', 'start_date' => '2025-01-01', 'status' => 'active', 'created_by' => $manager->id]);

        $this->actingAs($manager)->delete("/employment-contracts/{$draft->id}")->assertSessionHasNoErrors();
        $this->assertNull(EmploymentContract::find($draft->id));

        $this->actingAs($manager)->delete("/employment-contracts/{$active->id}")->assertSessionHasErrors();
        $this->assertNotNull(EmploymentContract::find($active->id));
    }

    public function test_a_branch_admin_cannot_manage_contracts_in_another_branch(): void
    {
        $managerA = $this->hrManager($this->branchA->id);
        $employeeB = $this->employee($this->branchB->id);

        $this->actingAs($managerA)->post("/employees/{$employeeB->id}/contracts", [
            'contract_type' => 'permanent', 'start_date' => '2026-01-01', 'status' => 'active',
        ])->assertForbidden();
    }

    public function test_someone_without_contract_permission_is_refused(): void
    {
        $bystander = $this->makeUser('teacher', $this->branchA->id, ['employee-view', 'employee-manage']);
        $employee = $this->employee($this->branchA->id);

        $this->actingAs($bystander)->post("/employees/{$employee->id}/contracts", [
            'contract_type' => 'permanent', 'start_date' => '2026-01-01', 'status' => 'active',
        ])->assertForbidden();
    }

    public function test_a_contract_document_is_not_served_to_someone_outside_the_employees_branch(): void
    {
        $managerA = $this->hrManager($this->branchA->id);
        $managerB = $this->hrManager($this->branchB->id);
        $employee = $this->employee($this->branchA->id);
        $this->actingAs($managerA)->post("/employees/{$employee->id}/contracts", [
            'contract_type' => 'permanent', 'start_date' => '2026-01-01', 'status' => 'active',
            'document' => UploadedFile::fake()->createWithContent('contract.pdf', self::PLAIN_PDF),
        ]);
        $contract = EmploymentContract::firstOrFail();

        $this->actingAs($managerB)->get("/employment-contracts/{$contract->id}/document")->assertForbidden();
    }

    public function test_check_contract_expiry_moves_a_contract_into_expiring_and_notifies_once(): void
    {
        Notification::fake();
        $manager = $this->hrManager($this->branchA->id);
        $employee = $this->employee($this->branchA->id);
        $contract = EmploymentContract::create(['employee_id' => $employee->id, 'contract_type' => 'fixed_term', 'start_date' => '2026-01-01', 'end_date' => now()->addDays(10)->toDateString(), 'status' => 'active', 'created_by' => $manager->id]);

        Artisan::call('hr:check-contract-expiry');

        $this->assertSame('expiring', $contract->fresh()->status);
        Notification::assertSentTo($manager, ContractExpiringSoon::class);

        Notification::fake();
        Artisan::call('hr:check-contract-expiry');
        Notification::assertNothingSent();
    }

    public function test_check_contract_expiry_moves_an_overdue_contract_into_expired(): void
    {
        $manager = $this->hrManager();
        $employee = $this->employee();
        $contract = EmploymentContract::create(['employee_id' => $employee->id, 'contract_type' => 'fixed_term', 'start_date' => '2025-01-01', 'end_date' => now()->subDay()->toDateString(), 'status' => 'expiring', 'expiry_notified' => true, 'created_by' => $manager->id]);

        Artisan::call('hr:check-contract-expiry');

        $this->assertSame('expired', $contract->fresh()->status);
    }

    public function test_a_draft_contract_never_auto_transitions_even_past_its_end_date(): void
    {
        $manager = $this->hrManager();
        $employee = $this->employee();
        $contract = EmploymentContract::create(['employee_id' => $employee->id, 'contract_type' => 'fixed_term', 'start_date' => '2025-01-01', 'end_date' => now()->subDay()->toDateString(), 'status' => 'draft', 'created_by' => $manager->id]);

        Artisan::call('hr:check-contract-expiry');

        $this->assertSame('draft', $contract->fresh()->status);
    }
}
