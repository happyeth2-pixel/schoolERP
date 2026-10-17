<?php

namespace App\Http\Controllers;

use App\Exceptions\DeletionBlockedException;
use App\Models\Employee;
use App\Models\LeaveAllocation;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Notifications\LeaveRequestReviewed;
use App\Notifications\LeaveRequestSubmitted;
use App\Rules\SafeUploadRule;
use App\Support\SafeUpload;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Leave requests -- HR Phase D. Confirmed via AskUserQuestion: self-service for anyone with a linked login
 * account (matching the brief's own "Teacher requests..." example), with HR/leave-approve holders able to
 * submit on behalf of anyone in their scope too. Draft -> Submitted -> Approved/Rejected, immutable once
 * submitted (same rule EmploymentContract/PurchaseOrder already follow), with the exact "a requester can
 * never review their own request" rule already used for Procurement and staff attendance corrections --
 * strengthened here to also block reviewing leave that is literally for YOURSELF, regardless of who clicked
 * submit on your behalf.
 */
class LeaveRequestController extends Controller
{
    /** Broad, administrative visibility -- the pending queue and documents show OTHER people's leave, so
     * merely holding leave-request (self-service) is deliberately not enough; see document()'s own-record
     * carve-out for the one case a self-service holder legitimately needs. */
    private function canView(User $user): bool
    {
        return $user->can('leave-view') || $user->can('leave-approve') || $user->hasRole('auditor');
    }

    private function assertEmployeeAccess(User $user, Employee $employee): void
    {
        abort_unless($employee->school_id === $user->school_id, 403);
        abort_unless($user->isOrgWide() || $employee->branch_id === $user->branch_id, 403);
    }

    /** Can $user create/edit a request for $employee: HR (leave-approve, in scope) or the employee themselves. */
    private function canActFor(User $user, Employee $employee): bool
    {
        if ($user->can('leave-approve') && ($user->isOrgWide() || $employee->branch_id === $user->branch_id)) {
            return true;
        }

        return $user->can('leave-request') && $employee->user_id === $user->id;
    }

    public function mine(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->can('leave-request') || $user->can('leave-approve'), 403);

        $employee = Employee::where('school_id', $user->school_id)->where('user_id', $user->id)->first();

        return Inertia::render('Hr/Leave/Mine', [
            'employee' => $employee ? ['id' => $employee->id, 'full_name' => $employee->full_name] : null,
            'leaveTypes' => LeaveType::where('school_id', $user->school_id)->orderBy('name')->get(['id', 'name', 'requires_document']),
            'allocations' => $employee ? $this->allocationsFor($employee) : [],
            'requests' => $employee ? $this->requestsFor($employee) : [],
        ]);
    }

    private function allocationsFor(Employee $employee): array
    {
        $year = (int) now()->year;

        return $employee->leaveAllocations()->where('year', $year)->with('leaveType')->get()->map(fn (LeaveAllocation $a) => [
            'id' => $a->id,
            'leave_type' => $a->leaveType->name,
            'year' => $a->year,
            'allocated_days' => (float) $a->allocated_days,
            'used_days' => $a->usedDays(),
            'balance' => $a->balance(),
        ])->all();
    }

    private function requestsFor(Employee $employee): array
    {
        return $employee->leaveRequests()->with('leaveType')->get()->map(fn (LeaveRequest $r) => [
            'id' => $r->id,
            'leave_type_id' => $r->leave_type_id,
            'leave_type' => $r->leaveType->name,
            'start_date' => $r->start_date->toDateString(),
            'end_date' => $r->end_date->toDateString(),
            'days_count' => (float) $r->days_count,
            'reason' => $r->reason,
            'status' => $r->status,
            'rejection_reason' => $r->rejection_reason,
            'has_document' => $r->document_path !== null,
            'can_edit' => $r->status === 'draft',
        ])->all();
    }

    private function rules(): array
    {
        return [
            'leave_type_id' => ['required', 'integer'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'reason' => ['required', 'string', 'max:1000'],
            'document' => ['nullable', 'file', 'max:5120', new SafeUploadRule(['pdf'])],
            'action' => ['required', Rule::in(['draft', 'submit'])],
        ];
    }

    public function store(Request $request, Employee $employee): RedirectResponse
    {
        $user = $request->user();
        abort_unless($this->canActFor($user, $employee), 403);
        $this->assertEmployeeAccess($user, $employee);

        $validated = $request->validate($this->rules());
        $leaveType = LeaveType::where('school_id', $user->school_id)->findOrFail($validated['leave_type_id']);

        if ($validated['action'] === 'submit') {
            $this->assertSubmittable($leaveType, $validated, $employee->id, null, $request->hasFile('document'));
        }

        $leaveRequest = $employee->leaveRequests()->create([
            'leave_type_id' => $leaveType->id,
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'],
            'days_count' => LeaveRequest::dayCount($validated['start_date'], $validated['end_date']),
            'reason' => $validated['reason'],
            'status' => $validated['action'] === 'submit' ? 'submitted' : 'draft',
            'requested_by' => $user->id,
        ]);

        if ($request->hasFile('document')) {
            $this->storeDocument($leaveRequest, $request->file('document'));
        }

        if ($leaveRequest->status === 'submitted') {
            $this->notifyApprovers($leaveRequest, $user);
        }

        return back()->with('success', $validated['action'] === 'submit' ? 'Leave request submitted.' : 'Saved as a draft.');
    }

    public function update(Request $request, LeaveRequest $leaveRequest): RedirectResponse
    {
        $user = $request->user();
        abort_unless($this->canActFor($user, $leaveRequest->employee), 403);
        abort_unless($leaveRequest->status === 'draft', 422, 'Only a draft can be changed.');

        $validated = $request->validate($this->rules());
        $leaveType = LeaveType::where('school_id', $user->school_id)->findOrFail($validated['leave_type_id']);

        if ($validated['action'] === 'submit') {
            $hasDocument = $request->hasFile('document') || $leaveRequest->document_path !== null;
            $this->assertSubmittable($leaveType, $validated, $leaveRequest->employee_id, $leaveRequest->id, $hasDocument);
        }

        $leaveRequest->update([
            'leave_type_id' => $leaveType->id,
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'],
            'days_count' => LeaveRequest::dayCount($validated['start_date'], $validated['end_date']),
            'reason' => $validated['reason'],
            'status' => $validated['action'] === 'submit' ? 'submitted' : 'draft',
        ]);

        if ($request->hasFile('document')) {
            $this->storeDocument($leaveRequest, $request->file('document'));
        }

        if ($leaveRequest->status === 'submitted') {
            $this->notifyApprovers($leaveRequest, $user);
        }

        return back()->with('success', $validated['action'] === 'submit' ? 'Leave request submitted.' : 'Draft updated.');
    }

    private function assertSubmittable(LeaveType $leaveType, array $validated, int $employeeId, ?int $excludingId, bool $hasDocument): void
    {
        if ($leaveType->requires_document && ! $hasDocument) {
            throw ValidationException::withMessages(['document' => "A supporting document is required for {$leaveType->name}."]);
        }
        if (LeaveRequest::overlapsExisting($employeeId, $validated['start_date'], $validated['end_date'], $excludingId)) {
            throw ValidationException::withMessages(['start_date' => 'This overlaps another submitted or approved leave request for the same employee.']);
        }
    }

    private function storeDocument(LeaveRequest $leaveRequest, $file): void
    {
        $old = $leaveRequest->document_path;
        $leaveRequest->update([
            'document_path' => SafeUpload::storePrivate($file, 'leave-requests', ['pdf']),
            'document_original_name' => $file->getClientOriginalName(),
        ]);
        if ($old) {
            Storage::disk('local')->delete($old);
        }
    }

    public function destroy(Request $request, LeaveRequest $leaveRequest): RedirectResponse
    {
        $user = $request->user();
        abort_unless($this->canActFor($user, $leaveRequest->employee), 403);

        try {
            if ($leaveRequest->document_path) {
                Storage::disk('local')->delete($leaveRequest->document_path);
            }
            $leaveRequest->delete();
        } catch (DeletionBlockedException $e) {
            return back()->withErrors(['leave' => $e->getMessage()]);
        }

        return back()->with('success', 'Draft removed.');
    }

    public function pending(Request $request): Response
    {
        $user = $request->user();
        abort_unless($this->canView($user), 403);

        $query = LeaveRequest::where('status', 'submitted')->with('employee', 'leaveType', 'requestedBy');
        if (! $user->isOrgWide()) {
            $query->whereHas('employee', fn ($q) => $q->where('branch_id', $user->branch_id));
        }

        $requests = $query->orderBy('start_date')->get()->map(fn (LeaveRequest $r) => [
            'id' => $r->id,
            'employee_name' => $r->employee->full_name,
            'leave_type' => $r->leaveType->name,
            'start_date' => $r->start_date->toDateString(),
            'end_date' => $r->end_date->toDateString(),
            'days_count' => (float) $r->days_count,
            'reason' => $r->reason,
            'requested_by' => $r->requestedBy->name,
            'has_document' => $r->document_path !== null,
            'can_review' => $r->requested_by !== $user->id && $r->employee->user_id !== $user->id,
        ]);

        return Inertia::render('Hr/Leave/Pending', [
            'requests' => $requests,
            'canManage' => $user->can('leave-approve'),
        ]);
    }

    private function assertCanReview(User $user, LeaveRequest $leaveRequest): void
    {
        abort_unless($user->can('leave-approve'), 403);
        abort_unless($user->isOrgWide() || $leaveRequest->employee->branch_id === $user->branch_id, 403);
        abort_unless($leaveRequest->status === 'submitted', 422);
        abort_if($leaveRequest->requested_by === $user->id, 403, 'You cannot review a leave request you submitted yourself.');
        abort_if($leaveRequest->employee->user_id === $user->id, 403, 'You cannot review your own leave request.');
    }

    public function approve(Request $request, LeaveRequest $leaveRequest): RedirectResponse
    {
        $user = $request->user();
        $this->assertCanReview($user, $leaveRequest);

        $leaveRequest->update(['status' => 'approved', 'reviewed_by' => $user->id, 'reviewed_at' => now()]);
        $this->notifyEmployee($leaveRequest);

        return back()->with('success', 'Leave request approved.');
    }

    public function reject(Request $request, LeaveRequest $leaveRequest): RedirectResponse
    {
        $user = $request->user();
        $this->assertCanReview($user, $leaveRequest);

        $validated = $request->validate(['rejection_reason' => ['required', 'string', 'max:1000']]);
        $leaveRequest->update(['status' => 'rejected', 'reviewed_by' => $user->id, 'reviewed_at' => now(), 'rejection_reason' => $validated['rejection_reason']]);
        $this->notifyEmployee($leaveRequest);

        return back()->with('success', 'Leave request rejected.');
    }

    public function document(Request $request, LeaveRequest $leaveRequest): SymfonyResponse
    {
        $user = $request->user();
        abort_unless($this->canView($user) || $leaveRequest->employee->user_id === $user->id, 403);
        $this->assertEmployeeAccess($user, $leaveRequest->employee);
        abort_unless($leaveRequest->document_path && Storage::disk('local')->exists($leaveRequest->document_path), 404);

        [$ext, $mime] = SafeUpload::servingType($leaveRequest->document_path);

        return Storage::disk('local')->response($leaveRequest->document_path, "leave-{$leaveRequest->id}.{$ext}", [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "sandbox; default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'",
            'Cache-Control' => 'private, no-store',
        ], 'inline');
    }

    private function notifyApprovers(LeaveRequest $leaveRequest, User $requester): void
    {
        $employee = $leaveRequest->employee;
        User::where('school_id', $employee->school_id)
            ->where('id', '!=', $requester->id)
            ->when($employee->user_id, fn ($q) => $q->where('id', '!=', $employee->user_id))
            ->where(fn ($q) => $q->where('branch_id', $employee->branch_id)->orWhereNull('branch_id'))
            ->get()
            ->filter(fn (User $u) => $u->can('leave-approve'))
            ->each(fn (User $u) => $u->notify(new LeaveRequestSubmitted($leaveRequest)));
    }

    private function notifyEmployee(LeaveRequest $leaveRequest): void
    {
        $employee = $leaveRequest->employee;
        if ($employee->user_id) {
            $employee->user->notify(new LeaveRequestReviewed($leaveRequest));
        }
    }
}
