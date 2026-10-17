<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\LeaveRequest;
use App\Models\PayrollRun;
use App\Models\PerformanceReview;
use App\Models\PerformanceReviewCycle;
use App\Models\StaffAttendance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

/**
 * HR Module, Phase G (final phase): a dashboard snapshot across everything Phases A-F built. No new
 * entities -- this is read-only aggregation, following the EXACT existing "null = not for you" convention
 * assetStats()/requisitionStats() already use on the same dashboard. Each sub-figure is independently gated
 * on its own phase's permission.
 */
class HrDashboardTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchool();
    }

    private function hr(User $user): ?array
    {
        return $this->props($this->actingAs($user)->get('/dashboard')->assertOk())['stats']['hr'] ?? null;
    }

    private function employeeFor(User $user, ?int $branchId = null): Employee
    {
        return Employee::create([
            'school_id' => $this->school->id, 'branch_id' => $branchId ?? $user->branch_id, 'user_id' => $user->id,
            'employee_number' => 'EMP-2026-'.$user->id, 'full_name' => $user->name, 'phone' => '0911000007',
            'hire_date' => '2026-01-01', 'employment_status' => 'active', 'employment_type' => 'full_time', 'created_by' => $this->owner->id,
        ]);
    }

    public function test_someone_without_employee_view_gets_no_hr_block_at_all(): void
    {
        $bystander = $this->makeUser('teacher', $this->branchA->id, []);
        $this->assertNull($this->hr($bystander));
    }

    public function test_employee_view_alone_shows_the_headcount_but_nothing_from_the_other_phases(): void
    {
        $hr = $this->makeUser('branch_admin', $this->branchA->id, ['employee-view']);
        $this->employeeFor($this->makeUser('teacher', $this->branchA->id, []), $this->branchA->id);

        $stats = $this->hr($hr);

        $this->assertSame(1, $stats['total_employees']);
        $this->assertNull($stats['contracts_expiring_soon']);
        $this->assertNull($stats['attendance_today']);
        $this->assertNull($stats['pending_leave_requests']);
        $this->assertNull($stats['reviews_finalized_this_year']);
        $this->assertNull($stats['latest_payroll_run']);
    }

    public function test_contracts_expiring_soon_counts_only_the_expiring_status_in_branch_scope(): void
    {
        $hr = $this->makeUser('branch_admin', $this->branchA->id, ['employee-view', 'contract-view']);
        $inBranch = $this->employeeFor($this->makeUser('teacher', $this->branchA->id, []), $this->branchA->id);
        $otherBranch = $this->employeeFor($this->makeUser('teacher', $this->branchB->id, []), $this->branchB->id);

        EmploymentContract::create(['employee_id' => $inBranch->id, 'contract_type' => 'permanent', 'start_date' => '2025-01-01', 'status' => 'expiring', 'created_by' => $this->owner->id]);
        EmploymentContract::create(['employee_id' => $inBranch->id, 'contract_type' => 'permanent', 'start_date' => '2020-01-01', 'status' => 'expired', 'created_by' => $this->owner->id]);
        EmploymentContract::create(['employee_id' => $otherBranch->id, 'contract_type' => 'permanent', 'start_date' => '2025-01-01', 'status' => 'expiring', 'created_by' => $this->owner->id]);

        $stats = $this->hr($hr);
        $this->assertSame(1, $stats['contracts_expiring_soon']);
    }

    public function test_staff_attendance_today_computes_the_marked_count_and_rate(): void
    {
        $hr = $this->makeUser('branch_admin', $this->branchA->id, ['employee-view', 'staff-attendance-view']);
        $present = $this->employeeFor($this->makeUser('teacher', $this->branchA->id, []), $this->branchA->id);
        $absent = $this->employeeFor($this->makeUser('accountant', $this->branchA->id, []), $this->branchA->id);
        $today = now()->toDateString();

        StaffAttendance::create(['employee_id' => $present->id, 'date' => $today, 'status' => 'present', 'recorded_by' => $hr->id]);
        StaffAttendance::create(['employee_id' => $absent->id, 'date' => $today, 'status' => 'absent', 'recorded_by' => $hr->id]);

        $stats = $this->hr($hr);
        $this->assertSame(2, $stats['attendance_today']['marked']);
        $this->assertSame(50.0, $stats['attendance_today']['rate']);
    }

    public function test_pending_leave_requests_counts_only_submitted_in_branch_scope(): void
    {
        $hr = $this->makeUser('branch_admin', $this->branchA->id, ['employee-view', 'leave-view']);
        $employee = $this->employeeFor($this->makeUser('teacher', $this->branchA->id, []), $this->branchA->id);
        $leaveType = \App\Models\LeaveType::create(['school_id' => $this->school->id, 'name' => 'Annual']);

        LeaveRequest::create(['employee_id' => $employee->id, 'leave_type_id' => $leaveType->id, 'start_date' => '2026-03-01', 'end_date' => '2026-03-02', 'days_count' => 2, 'reason' => 'x', 'status' => 'submitted', 'requested_by' => $hr->id]);
        LeaveRequest::create(['employee_id' => $employee->id, 'leave_type_id' => $leaveType->id, 'start_date' => '2026-04-01', 'end_date' => '2026-04-02', 'days_count' => 2, 'reason' => 'y', 'status' => 'draft', 'requested_by' => $hr->id]);

        $stats = $this->hr($hr);
        $this->assertSame(1, $stats['pending_leave_requests']);
    }

    public function test_reviews_finalized_this_year_excludes_drafts_and_other_years(): void
    {
        $hr = $this->makeUser('branch_admin', $this->branchA->id, ['employee-view', 'performance-view']);
        $employee = $this->employeeFor($this->makeUser('teacher', $this->branchA->id, []), $this->branchA->id);
        $cycle = PerformanceReviewCycle::create(['school_id' => $this->school->id, 'name' => 'Mid-Year 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-06-30']);
        $oldCycle = PerformanceReviewCycle::create(['school_id' => $this->school->id, 'name' => 'Annual 2024', 'start_date' => '2024-01-01', 'end_date' => '2024-12-31']);

        PerformanceReview::create(['employee_id' => $employee->id, 'cycle_id' => $cycle->id, 'rating' => 4, 'comments' => 'x', 'status' => 'finalized', 'reviewed_by' => $hr->id, 'finalized_at' => now()->setYear(2026)]);
        PerformanceReview::create(['employee_id' => $employee->id, 'cycle_id' => $oldCycle->id, 'rating' => 3, 'comments' => 'y', 'status' => 'finalized', 'reviewed_by' => $hr->id, 'finalized_at' => '2024-06-01']);

        $stats = $this->hr($hr);
        $this->assertSame(1, $stats['reviews_finalized_this_year']);
    }

    public function test_latest_payroll_run_picks_the_most_recent_year_and_month_in_branch_scope(): void
    {
        $hr = $this->makeUser('branch_admin', $this->branchA->id, ['employee-view', 'payroll-view']);
        PayrollRun::create(['school_id' => $this->school->id, 'branch_id' => $this->branchA->id, 'year' => 2026, 'month' => 2, 'status' => 'finalized', 'generated_by' => $hr->id]);
        PayrollRun::create(['school_id' => $this->school->id, 'branch_id' => $this->branchA->id, 'year' => 2026, 'month' => 4, 'status' => 'draft', 'generated_by' => $hr->id]);
        PayrollRun::create(['school_id' => $this->school->id, 'branch_id' => $this->branchB->id, 'year' => 2026, 'month' => 5, 'status' => 'finalized', 'generated_by' => $hr->id]);

        $stats = $this->hr($hr);
        $this->assertSame('draft', $stats['latest_payroll_run']['status']);
        $this->assertStringContainsString('2026', $stats['latest_payroll_run']['label']);
    }

    public function test_a_branch_scoped_admin_never_sees_another_branchs_figures(): void
    {
        $hr = $this->makeUser('branch_admin', $this->branchA->id, ['employee-view']);
        $this->employeeFor($this->makeUser('teacher', $this->branchA->id, []), $this->branchA->id);
        $this->employeeFor($this->makeUser('teacher', $this->branchB->id, []), $this->branchB->id);

        $stats = $this->hr($hr);
        $this->assertSame(1, $stats['total_employees']);
    }

    public function test_an_org_wide_owner_sees_every_branch(): void
    {
        $this->employeeFor($this->makeUser('teacher', $this->branchA->id, []), $this->branchA->id);
        $this->employeeFor($this->makeUser('teacher', $this->branchB->id, []), $this->branchB->id);

        $stats = $this->hr($this->owner);
        $this->assertSame(2, $stats['total_employees']);
    }

    public function test_an_auditor_sees_every_figure_read_only(): void
    {
        $auditor = $this->makeUser('auditor', null);
        $this->employeeFor($this->makeUser('teacher', $this->branchA->id, []), $this->branchA->id);

        $stats = $this->hr($auditor);
        $this->assertNotNull($stats);
        $this->assertSame(1, $stats['total_employees']);
        $this->assertNotNull($stats['attendance_today']);
        $this->assertNotNull($stats['pending_leave_requests']);
    }
}
