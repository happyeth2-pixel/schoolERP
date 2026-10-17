<?php

namespace Tests\Feature;

use App\Models\AccountingPeriod;
use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\User;
use App\Notifications\PayslipReady;
use App\Services\Payroll;
use App\Support\ChartOfAccountsTemplate;
use App\Support\NotificationPresenter;
use App\Support\PayrollTaxBracketDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

/** HR module Phase F: payroll foundation. Confirmed via AskUserQuestion -- basic salary (from the
 * employee's contract) + statutory tax + pension only this phase (no allowances/other deductions), and a
 * finalized run posts to the accounting engine best-effort, matching every other money-moving module. Tax
 * brackets seeded from researched, UNVERIFIED Ethiopian figures -- the calculation itself is pinned here. */
class PayrollTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchool();
        // A freshly created School (BuildsSchool's own School::create()) starts with NO defaults of any
        // kind, the same way AccountingEngineTest must explicitly install a chart of accounts -- the
        // migration only seeds brackets for schools that already existed when it ran.
        PayrollTaxBracketDefaults::install($this->school);
    }

    public function test_a_freshly_created_school_has_no_brackets_until_explicitly_installed(): void
    {
        $fresh = \App\Models\School::create(['name' => 'Fresh', 'code' => 'FRESH'.\Illuminate\Support\Str::random(4)]);
        $this->assertSame(0, $fresh->payrollTaxBrackets()->count());

        PayrollTaxBracketDefaults::install($fresh);
        $this->assertSame(6, $fresh->payrollTaxBrackets()->count());

        // calling it again must never duplicate
        PayrollTaxBracketDefaults::install($fresh);
        $this->assertSame(6, $fresh->payrollTaxBrackets()->count());
    }

    public function test_the_real_seeding_path_actually_installs_brackets_for_the_school_it_creates(): void
    {
        // Regression: the payroll_tax_brackets migration only seeds schools that already exist when it
        // runs -- `db:seed` (which creates the real/demo school) runs AFTER `migrate`, so without an
        // explicit install() call in the seeder itself, a freshly deployed school silently got zero
        // brackets and Payroll::calculateTax() would return 0 for every employee, forever, unnoticed.
        $this->seed(\Database\Seeders\DemoSchoolSeeder::class);
        $school = \App\Models\School::where('code', 'DEMO')->firstOrFail();
        $this->assertSame(6, $school->payrollTaxBrackets()->count());
    }

    private function employeeWithSalary(User $user, float $salary, ?int $branchId = null): Employee
    {
        $employee = Employee::create([
            'school_id' => $this->school->id, 'branch_id' => $branchId ?? $user->branch_id, 'user_id' => $user->id,
            'employee_number' => 'EMP-2026-'.$user->id, 'full_name' => $user->name, 'phone' => '0911000003',
            'hire_date' => '2026-01-01', 'employment_status' => 'active', 'employment_type' => 'full_time', 'created_by' => $this->owner->id,
        ]);
        EmploymentContract::create([
            'employee_id' => $employee->id, 'contract_type' => 'permanent', 'start_date' => '2026-01-01',
            'status' => 'active', 'salary_reference' => $salary, 'created_by' => $this->owner->id,
        ]);

        return $employee;
    }

    public function test_tax_brackets_seeded_by_the_migration_compute_the_researched_figures_correctly(): void
    {
        $cases = [1500 => 0.0, 3000 => 150.0, 5000 => 500.0, 8000 => 1150.0, 12000 => 2250.0, 20000 => 4950.0];
        foreach ($cases as $income => $expected) {
            $this->assertSame($expected, Payroll::calculateTax($this->school, (float) $income), "income {$income}");
        }
    }

    /** §11 hardening: the classic progressive-tax bug is a gap or an overlap exactly AT a bracket boundary
     * (a cent either side taxed completely differently, or two brackets both matching, or neither). Every
     * boundary in the seeded table is checked on both sides, plus that the formula is continuous across
     * each one (an extra cent of salary should never cost more than an extra cent of tax). */
    public function test_tax_calculation_is_exact_and_continuous_at_every_bracket_boundary(): void
    {
        $boundaries = [
            [2000.00, 0.0], [2000.01, 0.0015],
            [4000.00, 300.0], [4000.01, 300.0020],
            [7000.00, 900.0], [7000.01, 900.0025],
            [10000.00, 1650.0], [10000.01, 1650.0030],
            [14000.00, 2850.0], [14000.01, 2850.0035],
        ];
        foreach ($boundaries as [$income, $expected]) {
            $this->assertSame(round($expected, 2), Payroll::calculateTax($this->school, $income), "income {$income}");
        }

        // continuity: stepping one cent past a boundary must never jump the tax by more than that cent's
        // worth (within the bracket's own marginal rate) -- it would be a real bug if it did.
        foreach ([2000.00, 4000.00, 7000.00, 10000.00, 14000.00] as $boundary) {
            $below = Payroll::calculateTax($this->school, $boundary);
            $above = Payroll::calculateTax($this->school, $boundary + 0.01);
            $this->assertLessThan(0.01, $above - $below, "a one-cent raise must not jump tax at the {$boundary} boundary");
        }
    }

    public function test_tax_and_pension_are_computed_on_full_gross_never_a_pension_reduced_base(): void
    {
        // Documented, deliberate design: PAYE and pension both read the contract's full salary_reference
        // directly, never each other's result -- confirmed by research, pinned here against a regression.
        $hr = $this->makeUser('branch_admin', $this->branchA->id, ['payroll-manage']);
        $this->employeeWithSalary($this->makeUser('teacher', $this->branchA->id, []), 8000, $this->branchA->id);

        $this->actingAs($hr)->post('/payroll-runs', ['branch_id' => $this->branchA->id, 'period' => '2026-04']);
        $slip = Payslip::firstOrFail();
        // School::create() in setUp() doesn't pass pension rates explicitly, and Eloquent's create() never
        // re-reads DB column defaults afterward -- ->fresh() is required to see the real seeded 0.07/0.11
        // (the same pattern this file's own pension-rate-editing test already uses).
        $school = $this->school->fresh();

        $this->assertSame(8000.0, (float) $slip->taxable_income, 'tax is computed on the full salary, not salary-minus-pension');
        $this->assertSame(1150.0, (float) $slip->income_tax);
        $this->assertSame(round(8000 * (float) $school->pension_employee_rate, 2), (float) $slip->employee_pension);
        $this->assertSame(round(8000 * (float) $school->pension_employer_rate, 2), (float) $slip->employer_pension);
        $this->assertSame(round(8000 - 1150 - (float) $slip->employee_pension, 2), (float) $slip->net_pay, 'only the EMPLOYEE share of pension reduces take-home pay, never the employer share');
    }

    public function test_generating_a_run_computes_tax_and_pension_correctly_and_skips_ineligible_employees(): void
    {
        $hr = $this->makeUser('branch_admin', $this->branchA->id, ['payroll-manage']);
        $paid = $this->employeeWithSalary($this->makeUser('teacher', $this->branchA->id, []), 8000, $this->branchA->id);

        $noContract = Employee::create([
            'school_id' => $this->school->id, 'branch_id' => $this->branchA->id, 'employee_number' => 'EMP-2026-NC',
            'full_name' => 'No Contract Person', 'phone' => '0911000004', 'hire_date' => '2026-01-01',
            'employment_status' => 'active', 'employment_type' => 'full_time', 'created_by' => $this->owner->id,
        ]);
        $noSalary = Employee::create([
            'school_id' => $this->school->id, 'branch_id' => $this->branchA->id, 'employee_number' => 'EMP-2026-NS',
            'full_name' => 'No Salary Person', 'phone' => '0911000005', 'hire_date' => '2026-01-01',
            'employment_status' => 'active', 'employment_type' => 'full_time', 'created_by' => $this->owner->id,
        ]);
        EmploymentContract::create(['employee_id' => $noSalary->id, 'contract_type' => 'permanent', 'start_date' => '2026-01-01', 'status' => 'active', 'salary_reference' => null, 'created_by' => $this->owner->id]);

        $this->actingAs($hr)->post('/payroll-runs', ['branch_id' => $this->branchA->id, 'period' => '2026-03'])->assertSessionHasNoErrors();

        $run = PayrollRun::firstOrFail();
        $this->assertSame('draft', $run->status);
        $this->assertSame(1, $run->payslips()->count());

        $payslip = $run->payslips()->firstOrFail();
        $this->assertSame($paid->id, $payslip->employee_id);
        $this->assertEquals(8000, (float) $payslip->basic_salary);
        $this->assertEquals(1150.0, (float) $payslip->income_tax);
        $this->assertEquals(560.0, (float) $payslip->employee_pension); // 7% of 8000
        $this->assertEquals(880.0, (float) $payslip->employer_pension); // 11% of 8000
        $this->assertEquals(8000 - 1150 - 560, (float) $payslip->net_pay);
    }

    public function test_a_second_run_for_the_same_branch_and_month_is_refused_in_plain_words(): void
    {
        $hr = $this->makeUser('branch_admin', $this->branchA->id, ['payroll-manage']);
        $this->employeeWithSalary($this->makeUser('teacher', $this->branchA->id, []), 5000, $this->branchA->id);

        $this->actingAs($hr)->post('/payroll-runs', ['branch_id' => $this->branchA->id, 'period' => '2026-03'])->assertSessionHasNoErrors();
        $this->actingAs($hr)->post('/payroll-runs', ['branch_id' => $this->branchA->id, 'period' => '2026-03'])->assertSessionHasErrors('period');

        $this->assertSame(1, PayrollRun::count());
    }

    public function test_a_draft_run_can_be_deleted_but_a_finalized_one_cannot(): void
    {
        $hr = $this->makeUser('branch_admin', $this->branchA->id, ['payroll-manage']);
        $this->employeeWithSalary($this->makeUser('teacher', $this->branchA->id, []), 5000, $this->branchA->id);
        $this->actingAs($hr)->post('/payroll-runs', ['branch_id' => $this->branchA->id, 'period' => '2026-03']);
        $run = PayrollRun::firstOrFail();

        $this->actingAs($hr)->delete("/payroll-runs/{$run->id}")->assertSessionHasNoErrors();
        $this->assertNull(PayrollRun::find($run->id));

        $this->employeeWithSalary($this->makeUser('teacher', $this->branchA->id, []), 5000, $this->branchA->id);
        $this->actingAs($hr)->post('/payroll-runs', ['branch_id' => $this->branchA->id, 'period' => '2026-04']);
        $run2 = PayrollRun::firstOrFail();
        $this->actingAs($hr)->post("/payroll-runs/{$run2->id}/finalize")->assertSessionHasNoErrors();

        $this->actingAs($hr)->delete("/payroll-runs/{$run2->id}")->assertSessionHasErrors();
        $this->assertNotNull(PayrollRun::find($run2->id));
    }

    public function test_finalizing_notifies_each_employee_and_links_to_their_own_payslip_page(): void
    {
        Notification::fake();
        $hr = $this->makeUser('branch_admin', $this->branchA->id, ['payroll-manage']);
        $teacher = $this->makeUser('teacher', $this->branchA->id, []);
        $this->employeeWithSalary($teacher, 5000, $this->branchA->id);
        $this->actingAs($hr)->post('/payroll-runs', ['branch_id' => $this->branchA->id, 'period' => '2026-03']);
        $run = PayrollRun::firstOrFail();

        $this->actingAs($hr)->post("/payroll-runs/{$run->id}/finalize")->assertSessionHasNoErrors();

        Notification::assertSentTo($teacher, PayslipReady::class);
        $this->assertSame('finalized', $run->fresh()->status);
        $this->assertNotNull($run->fresh()->finalized_at);
    }

    public function test_finalizing_posts_a_balanced_entry_to_accounting_when_configured_and_skips_silently_when_not(): void
    {
        $hr = $this->makeUser('branch_admin', $this->branchA->id, ['payroll-manage']);
        $this->employeeWithSalary($this->makeUser('teacher', $this->branchA->id, []), 8000, $this->branchA->id);
        $this->actingAs($hr)->post('/payroll-runs', ['branch_id' => $this->branchA->id, 'period' => '2026-03']);
        $run = PayrollRun::firstOrFail();

        // not configured yet -- finalizing must still succeed, just without posting
        $this->actingAs($hr)->post("/payroll-runs/{$run->id}/finalize")->assertSessionHasNoErrors();
        $this->assertNull($run->fresh()->journal_entry_id);

        // now configure accounting and finalize a second run
        ChartOfAccountsTemplate::install($this->school);
        AccountingPeriod::create(['school_id' => $this->school->id, 'name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open']);
        $this->employeeWithSalary($this->makeUser('teacher', $this->branchA->id, []), 8000, $this->branchA->id);
        $this->actingAs($hr)->post('/payroll-runs', ['branch_id' => $this->branchA->id, 'period' => '2026-04']);
        $run2 = PayrollRun::where('month', 4)->firstOrFail();

        $this->actingAs($hr)->post("/payroll-runs/{$run2->id}/finalize")->assertSessionHasNoErrors();
        $run2->refresh();
        $this->assertNotNull($run2->journal_entry_id);

        $entry = $run2->journalEntry;
        $this->assertSame('posted', $entry->status);
        $debits = $entry->lines->sum('debit');
        $credits = $entry->lines->sum('credit');
        $this->assertEquals($debits, $credits);
        $this->assertGreaterThan(0, $debits);
    }

    public function test_an_employee_sees_only_their_finalized_payslip_never_a_draft(): void
    {
        $hr = $this->makeUser('branch_admin', $this->branchA->id, ['payroll-manage']);
        $teacher = $this->makeUser('teacher', $this->branchA->id, []);
        $this->employeeWithSalary($teacher, 5000, $this->branchA->id);
        $this->actingAs($hr)->post('/payroll-runs', ['branch_id' => $this->branchA->id, 'period' => '2026-03']);
        $draftRun = PayrollRun::firstOrFail();

        $response = $this->actingAs($teacher)->get('/my-payslips');
        $response->assertInertia(fn ($page) => $page->has('payslips', 0));

        $this->actingAs($hr)->post("/payroll-runs/{$draftRun->id}/finalize");

        $response = $this->actingAs($teacher)->get('/my-payslips');
        $response->assertInertia(fn ($page) => $page->has('payslips', 1));
    }

    public function test_branch_scoping_refuses_a_run_outside_the_actors_branch(): void
    {
        $hr = $this->makeUser('branch_admin', $this->branchA->id, ['payroll-manage']);
        $this->actingAs($hr)->post('/payroll-runs', ['branch_id' => $this->branchB->id, 'period' => '2026-03'])->assertForbidden();
    }

    public function test_someone_without_any_payroll_permission_is_refused(): void
    {
        $bystander = $this->makeUser('teacher', $this->branchA->id, []);
        $this->actingAs($bystander)->get('/payroll-runs')->assertForbidden();
        $this->actingAs($bystander)->get('/payroll-settings')->assertForbidden();
        $this->actingAs($bystander)->post('/payroll-runs', ['branch_id' => $this->branchA->id, 'period' => '2026-03'])->assertForbidden();
    }

    public function test_pension_rates_and_a_tax_bracket_are_editable_by_payroll_manage_only(): void
    {
        $hr = $this->makeUser('branch_admin', $this->branchA->id, ['payroll-manage']);
        $viewer = $this->makeUser('branch_admin', $this->branchA->id, ['payroll-view']);

        $this->actingAs($hr)->put('/payroll-settings/pension', ['pension_employee_rate' => '0.08', 'pension_employer_rate' => '0.12'])->assertSessionHasNoErrors();
        $this->assertEquals(0.08, (float) $this->school->fresh()->pension_employee_rate);

        $this->actingAs($viewer)->put('/payroll-settings/pension', ['pension_employee_rate' => '0.5', 'pension_employer_rate' => '0.5'])->assertForbidden();

        $this->actingAs($hr)->post('/payroll-settings/brackets', ['min_amount' => '0', 'max_amount' => '500', 'rate' => '0.05', 'deduction' => '0'])->assertSessionHasNoErrors();
        $this->assertSame(7, $this->school->payrollTaxBrackets()->count());
    }

    public function test_the_payslip_ready_notification_links_to_my_payslips(): void
    {
        $hr = $this->makeUser('branch_admin', $this->branchA->id, ['payroll-manage']);
        $teacher = $this->makeUser('teacher', $this->branchA->id, []);
        $employee = $this->employeeWithSalary($teacher, 5000, $this->branchA->id);
        $this->actingAs($hr)->post('/payroll-runs', ['branch_id' => $this->branchA->id, 'period' => '2026-03']);
        $run = PayrollRun::firstOrFail();
        $this->actingAs($hr)->post("/payroll-runs/{$run->id}/finalize");

        $payslip = Payslip::where('employee_id', $employee->id)->firstOrFail();
        $teacher->notify(new PayslipReady($payslip));
        $presented = NotificationPresenter::present($teacher->notifications()->latest()->first());

        $this->assertSame(route('payslips.mine'), $presented['action_url']);
        $this->actingAs($teacher)->get($presented['action_url'])->assertOk();
    }
}
