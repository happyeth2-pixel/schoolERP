<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LeaveAllocation;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Notifications\LeaveRequestReviewed;
use App\Notifications\LeaveRequestSubmitted;
use App\Support\NotificationPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

/** HR module Phase D: leave management. Confirmed via AskUserQuestion -- self-service for anyone with a
 * linked account, HR (leave-approve) can also submit on behalf of anyone in scope. Draft -> Submitted ->
 * Approved/Rejected, with the balance always live-computed from approved requests, never cached. */
class LeaveManagementTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    private const PLAIN_PDF = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF";

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchool();
    }

    private function leaveType(array $over = []): LeaveType
    {
        return LeaveType::create(array_merge(['school_id' => $this->school->id, 'name' => 'Annual Leave', 'default_annual_days' => 16], $over));
    }

    private function employeeFor(User $user, ?int $branchId = null): Employee
    {
        return Employee::create([
            'school_id' => $this->school->id, 'branch_id' => $branchId ?? $user->branch_id, 'user_id' => $user->id,
            'employee_number' => 'EMP-2026-'.$user->id, 'full_name' => $user->name, 'phone' => '0911000001',
            'hire_date' => '2026-01-01', 'employment_status' => 'active', 'employment_type' => 'full_time', 'created_by' => $this->owner->id,
        ]);
    }

    public function test_an_employee_can_submit_their_own_draft_and_then_submit_it(): void
    {
        $teacher = $this->makeUser('teacher', $this->branchA->id, ['leave-request']);
        $employee = $this->employeeFor($teacher, $this->branchA->id);
        $leaveType = $this->leaveType();

        $this->actingAs($teacher)->post("/employees/{$employee->id}/leave-requests", [
            'leave_type_id' => $leaveType->id, 'start_date' => '2026-03-02', 'end_date' => '2026-03-04',
            'reason' => 'Family event', 'action' => 'draft',
        ])->assertSessionHasNoErrors();

        $leaveRequest = LeaveRequest::firstOrFail();
        $this->assertSame('draft', $leaveRequest->status);
        $this->assertSame(3.0, (float) $leaveRequest->days_count);

        $this->actingAs($teacher)->put("/leave-requests/{$leaveRequest->id}", [
            'leave_type_id' => $leaveType->id, 'start_date' => '2026-03-02', 'end_date' => '2026-03-04',
            'reason' => 'Family event', 'action' => 'submit',
        ])->assertSessionHasNoErrors();

        $this->assertSame('submitted', $leaveRequest->fresh()->status);
    }

    public function test_someone_with_leave_request_only_cannot_submit_for_another_employee(): void
    {
        $teacher = $this->makeUser('teacher', $this->branchA->id, ['leave-request']);
        $otherTeacher = $this->makeUser('teacher', $this->branchA->id, []);
        $otherEmployee = $this->employeeFor($otherTeacher, $this->branchA->id);
        $leaveType = $this->leaveType();

        $this->actingAs($teacher)->post("/employees/{$otherEmployee->id}/leave-requests", [
            'leave_type_id' => $leaveType->id, 'start_date' => '2026-03-02', 'end_date' => '2026-03-04',
            'reason' => 'x', 'action' => 'draft',
        ])->assertForbidden();
    }

    public function test_leave_approve_can_submit_on_behalf_of_any_employee_in_scope(): void
    {
        $hr = $this->makeUser('branch_admin', $this->branchA->id, ['leave-approve']);
        $teacher = $this->makeUser('teacher', $this->branchA->id, []);
        $employee = $this->employeeFor($teacher, $this->branchA->id);
        $leaveType = $this->leaveType();

        $this->actingAs($hr)->post("/employees/{$employee->id}/leave-requests", [
            'leave_type_id' => $leaveType->id, 'start_date' => '2026-03-02', 'end_date' => '2026-03-04',
            'reason' => 'On behalf', 'action' => 'submit',
        ])->assertSessionHasNoErrors();

        $this->assertSame('submitted', LeaveRequest::firstOrFail()->status);
    }

    public function test_submitting_without_a_required_document_is_refused(): void
    {
        $teacher = $this->makeUser('teacher', $this->branchA->id, ['leave-request']);
        $employee = $this->employeeFor($teacher, $this->branchA->id);
        $leaveType = $this->leaveType(['name' => 'Sick Leave', 'requires_document' => true]);

        $this->actingAs($teacher)->post("/employees/{$employee->id}/leave-requests", [
            'leave_type_id' => $leaveType->id, 'start_date' => '2026-03-02', 'end_date' => '2026-03-03',
            'reason' => 'Unwell', 'action' => 'submit',
        ])->assertSessionHasErrors('document');

        $this->assertSame(0, LeaveRequest::count());

        $this->actingAs($teacher)->post("/employees/{$employee->id}/leave-requests", [
            'leave_type_id' => $leaveType->id, 'start_date' => '2026-03-02', 'end_date' => '2026-03-03',
            'reason' => 'Unwell', 'action' => 'submit', 'document' => UploadedFile::fake()->createWithContent('note.pdf', self::PLAIN_PDF),
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, LeaveRequest::count());
    }

    public function test_overlapping_submitted_leave_for_the_same_employee_is_refused(): void
    {
        $teacher = $this->makeUser('teacher', $this->branchA->id, ['leave-request']);
        $employee = $this->employeeFor($teacher, $this->branchA->id);
        $leaveType = $this->leaveType();
        LeaveRequest::create(['employee_id' => $employee->id, 'leave_type_id' => $leaveType->id, 'start_date' => '2026-03-02', 'end_date' => '2026-03-05', 'days_count' => 4, 'reason' => 'first', 'status' => 'submitted', 'requested_by' => $teacher->id]);

        $this->actingAs($teacher)->post("/employees/{$employee->id}/leave-requests", [
            'leave_type_id' => $leaveType->id, 'start_date' => '2026-03-04', 'end_date' => '2026-03-06',
            'reason' => 'overlap', 'action' => 'submit',
        ])->assertSessionHasErrors('start_date');

        $this->assertSame(1, LeaveRequest::count());
    }

    public function test_a_draft_can_be_removed_but_a_submitted_one_cannot(): void
    {
        $teacher = $this->makeUser('teacher', $this->branchA->id, ['leave-request']);
        $employee = $this->employeeFor($teacher, $this->branchA->id);
        $leaveType = $this->leaveType();
        $draft = LeaveRequest::create(['employee_id' => $employee->id, 'leave_type_id' => $leaveType->id, 'start_date' => '2026-03-02', 'end_date' => '2026-03-03', 'days_count' => 2, 'reason' => 'x', 'status' => 'draft', 'requested_by' => $teacher->id]);
        $submitted = LeaveRequest::create(['employee_id' => $employee->id, 'leave_type_id' => $leaveType->id, 'start_date' => '2026-04-02', 'end_date' => '2026-04-03', 'days_count' => 2, 'reason' => 'y', 'status' => 'submitted', 'requested_by' => $teacher->id]);

        $this->actingAs($teacher)->delete("/leave-requests/{$draft->id}")->assertSessionHasNoErrors();
        $this->assertNull(LeaveRequest::find($draft->id));

        $this->actingAs($teacher)->delete("/leave-requests/{$submitted->id}")->assertSessionHasErrors();
        $this->assertNotNull(LeaveRequest::find($submitted->id));
    }

    public function test_approving_a_request_is_reflected_live_in_the_balance(): void
    {
        Notification::fake();
        $teacher = $this->makeUser('teacher', $this->branchA->id, ['leave-request']);
        $hr = $this->makeUser('branch_admin', $this->branchA->id, ['leave-approve']);
        $employee = $this->employeeFor($teacher, $this->branchA->id);
        $leaveType = $this->leaveType();
        $allocation = LeaveAllocation::create(['employee_id' => $employee->id, 'leave_type_id' => $leaveType->id, 'year' => 2026, 'allocated_days' => 16, 'created_by' => $hr->id]);
        $leaveRequest = LeaveRequest::create(['employee_id' => $employee->id, 'leave_type_id' => $leaveType->id, 'start_date' => '2026-03-02', 'end_date' => '2026-03-04', 'days_count' => 3, 'reason' => 'x', 'status' => 'submitted', 'requested_by' => $teacher->id]);

        $this->assertSame(16.0, $allocation->balance());

        $this->actingAs($hr)->post("/leave-requests/{$leaveRequest->id}/approve")->assertRedirect();

        $this->assertSame('approved', $leaveRequest->fresh()->status);
        $this->assertSame(13.0, $allocation->fresh()->balance());
        Notification::assertSentTo($teacher, LeaveRequestReviewed::class);
    }

    public function test_a_requester_cannot_approve_their_own_submitted_request(): void
    {
        $hr = $this->makeUser('branch_admin', $this->branchA->id, ['leave-approve']);
        $teacher = $this->makeUser('teacher', $this->branchA->id, []);
        $employee = $this->employeeFor($teacher, $this->branchA->id);
        $leaveType = $this->leaveType();
        $leaveRequest = LeaveRequest::create(['employee_id' => $employee->id, 'leave_type_id' => $leaveType->id, 'start_date' => '2026-03-02', 'end_date' => '2026-03-03', 'days_count' => 2, 'reason' => 'x', 'status' => 'submitted', 'requested_by' => $hr->id]);

        $this->actingAs($hr)->post("/leave-requests/{$leaveRequest->id}/approve")->assertForbidden();
    }

    public function test_an_employee_cannot_approve_leave_that_is_literally_their_own_even_if_someone_else_submitted_it(): void
    {
        $hrWhoIsAlsoAnEmployee = $this->makeUser('branch_admin', $this->branchA->id, ['leave-approve']);
        $thirdPartyHr = $this->makeUser('branch_admin', $this->branchA->id, ['leave-approve']);
        $submitter = $this->makeUser('branch_admin', $this->branchA->id, ['leave-approve']);
        $employee = $this->employeeFor($hrWhoIsAlsoAnEmployee, $this->branchA->id);
        $leaveType = $this->leaveType();
        // submitter (an uninvolved third party) enters it on hrWhoIsAlsoAnEmployee's behalf -- but it is still THEIR OWN leave
        $leaveRequest = LeaveRequest::create(['employee_id' => $employee->id, 'leave_type_id' => $leaveType->id, 'start_date' => '2026-03-02', 'end_date' => '2026-03-03', 'days_count' => 2, 'reason' => 'x', 'status' => 'submitted', 'requested_by' => $submitter->id]);

        // blocked: the requester themselves
        $this->actingAs($submitter)->post("/leave-requests/{$leaveRequest->id}/approve")->assertForbidden();
        // blocked: the employee the leave is literally for, even though someone else clicked submit
        $this->actingAs($hrWhoIsAlsoAnEmployee)->post("/leave-requests/{$leaveRequest->id}/approve")->assertForbidden();
        // allowed: a fully uninvolved HR admin
        $this->actingAs($thirdPartyHr)->post("/leave-requests/{$leaveRequest->id}/approve")->assertRedirect();
    }

    public function test_rejecting_requires_a_reason_and_leaves_the_balance_unchanged(): void
    {
        $hr = $this->makeUser('branch_admin', $this->branchA->id, ['leave-approve']);
        $teacher = $this->makeUser('teacher', $this->branchA->id, []);
        $employee = $this->employeeFor($teacher, $this->branchA->id);
        $leaveType = $this->leaveType();
        $allocation = LeaveAllocation::create(['employee_id' => $employee->id, 'leave_type_id' => $leaveType->id, 'year' => 2026, 'allocated_days' => 16, 'created_by' => $hr->id]);
        $leaveRequest = LeaveRequest::create(['employee_id' => $employee->id, 'leave_type_id' => $leaveType->id, 'start_date' => '2026-03-02', 'end_date' => '2026-03-04', 'days_count' => 3, 'reason' => 'x', 'status' => 'submitted', 'requested_by' => $teacher->id]);

        $this->actingAs($hr)->post("/leave-requests/{$leaveRequest->id}/reject", [])->assertSessionHasErrors('rejection_reason');

        $this->actingAs($hr)->post("/leave-requests/{$leaveRequest->id}/reject", ['rejection_reason' => 'Short-staffed that week'])->assertRedirect();

        $this->assertSame('rejected', $leaveRequest->fresh()->status);
        $this->assertSame(16.0, $allocation->fresh()->balance());
    }

    public function test_a_leave_type_cannot_be_removed_while_in_use(): void
    {
        $hr = $this->makeUser('branch_admin', $this->branchA->id, ['leave-approve']);
        $leaveType = $this->leaveType();
        $employee = $this->employeeFor($this->makeUser('teacher', $this->branchA->id, []), $this->branchA->id);
        LeaveAllocation::create(['employee_id' => $employee->id, 'leave_type_id' => $leaveType->id, 'year' => 2026, 'allocated_days' => 16, 'created_by' => $hr->id]);

        $this->actingAs($hr)->delete("/leave-types/{$leaveType->id}")->assertSessionHasErrors();
        $this->assertNotNull(LeaveType::find($leaveType->id));
    }

    public function test_someone_without_any_leave_permission_is_refused(): void
    {
        $bystander = $this->makeUser('teacher', $this->branchA->id, []);
        $employee = $this->employeeFor($this->makeUser('teacher', $this->branchA->id, []), $this->branchA->id);
        $leaveType = $this->leaveType();

        $this->actingAs($bystander)->post("/employees/{$employee->id}/leave-requests", [
            'leave_type_id' => $leaveType->id, 'start_date' => '2026-03-02', 'end_date' => '2026-03-03',
            'reason' => 'x', 'action' => 'draft',
        ])->assertForbidden();
        $this->actingAs($bystander)->get('/my-leave')->assertForbidden();
    }

    public function test_self_service_only_cannot_browse_the_pending_queue_or_a_colleagues_document_but_can_see_their_own(): void
    {
        $teacher = $this->makeUser('teacher', $this->branchA->id, ['leave-request']);
        $colleague = $this->makeUser('teacher', $this->branchA->id, []);
        $employee = $this->employeeFor($teacher, $this->branchA->id);
        $colleagueEmployee = $this->employeeFor($colleague, $this->branchA->id);
        $leaveType = $this->leaveType();

        $ownRequest = LeaveRequest::create(['employee_id' => $employee->id, 'leave_type_id' => $leaveType->id, 'start_date' => '2026-03-02', 'end_date' => '2026-03-03', 'days_count' => 2, 'reason' => 'x', 'status' => 'submitted', 'requested_by' => $teacher->id, 'document_path' => 'leave-requests/fake.pdf']);
        $colleagueRequest = LeaveRequest::create(['employee_id' => $colleagueEmployee->id, 'leave_type_id' => $leaveType->id, 'start_date' => '2026-04-02', 'end_date' => '2026-04-03', 'days_count' => 2, 'reason' => 'y', 'status' => 'submitted', 'requested_by' => $colleague->id, 'document_path' => 'leave-requests/fake2.pdf']);

        $this->actingAs($teacher)->get('/leave-requests/pending')->assertForbidden();
        $this->actingAs($teacher)->get("/leave-requests/{$colleagueRequest->id}/document")->assertForbidden();
        $this->actingAs($teacher)->get("/leave-requests/{$ownRequest->id}/document")->assertStatus(404); // authorized, just no real file on disk in this test
    }

    public function test_the_reviewed_notification_links_a_self_service_recipient_to_their_own_page_not_the_hr_only_one(): void
    {
        // Regression: NotificationPresenter used to point this at employees.show, which 403s for exactly
        // the self-service-only recipient (no employee-view) this notification is sent to.
        $teacher = $this->makeUser('teacher', $this->branchA->id, ['leave-request']);
        $employee = $this->employeeFor($teacher, $this->branchA->id);
        $leaveType = $this->leaveType();
        $leaveRequest = LeaveRequest::create(['employee_id' => $employee->id, 'leave_type_id' => $leaveType->id, 'start_date' => '2026-03-02', 'end_date' => '2026-03-03', 'days_count' => 2, 'reason' => 'x', 'status' => 'approved', 'requested_by' => $teacher->id]);

        $teacher->notify(new LeaveRequestReviewed($leaveRequest));
        $presented = NotificationPresenter::present($teacher->notifications()->first());

        $this->assertSame(route('leave-requests.mine'), $presented['action_url']);
        $this->actingAs($teacher)->get($presented['action_url'])->assertOk();
    }
}
