<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\StaffAttendance;
use App\Models\StaffAttendanceCorrection;
use App\Models\User;
use App\Notifications\AttendanceCorrectionRequested;
use App\Notifications\AttendanceCorrectionReviewed;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Staff attendance -- HR Phase C. Deliberately HR/admin-recorded for everyone (confirmed via
 * AskUserQuestion: no self-service check-in in this phase), and deliberately a SEPARATE table from student
 * attendance. The first entry for a day is written directly; changing an already-recorded day goes through
 * `StaffAttendanceCorrection` instead of a plain update -- see that model's own docblock for why this phase
 * needs real two-person approval where student attendance's correction flow does not.
 */
class StaffAttendanceController extends Controller
{
    private function canView(User $user): bool
    {
        return $user->can('staff-attendance-view') || $user->can('staff-attendance-manage') || $user->hasRole('auditor');
    }

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($this->canView($user), 403);

        $date = $request->query('date', now()->toDateString());

        $query = Employee::whereIn('employment_status', ['active', 'on_leave'])->where('school_id', $user->school_id);
        if (! $user->isOrgWide()) {
            $query->where('branch_id', $user->branch_id);
        }

        $employees = $query->orderBy('full_name')->get();
        $attendances = StaffAttendance::whereIn('employee_id', $employees->pluck('id'))->whereDate('date', $date)
            ->with('corrections')->get()->keyBy('employee_id');

        $roster = $employees->map(function (Employee $e) use ($attendances, $user) {
            $a = $attendances->get($e->id);
            $pendingCorrection = $a?->corrections->firstWhere('status', 'pending');

            return [
                'employee_id' => $e->id,
                'full_name' => $e->full_name,
                'employee_number' => $e->employee_number,
                'attendance_id' => $a?->id,
                'status' => $a?->status,
                'check_in' => $a?->check_in,
                'check_out' => $a?->check_out,
                'overtime_minutes' => $a?->overtime_minutes,
                'recorded_by' => $a?->recordedBy->name,
                'pending_correction' => $pendingCorrection ? [
                    'id' => $pendingCorrection->id,
                    'requested_by' => $pendingCorrection->requestedBy->name,
                    'can_review' => $pendingCorrection->requested_by !== $user->id,
                ] : null,
            ];
        });

        return Inertia::render('Hr/Attendance/Index', [
            'date' => $date,
            'today' => now()->toDateString(),
            'roster' => $roster,
            'canManage' => $user->can('staff-attendance-manage'),
            'statuses' => StaffAttendance::STATUSES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('staff-attendance-manage'), 403);

        $validated = $request->validate([
            'date' => ['required', 'date'],
            'records' => ['required', 'array', 'min:1'],
            'records.*.employee_id' => ['required', 'integer', Rule::exists('employees', 'id')->where('school_id', $user->school_id)],
            'records.*.status' => ['required', Rule::in(StaffAttendance::STATUSES)],
            'records.*.check_in' => ['nullable', 'date_format:H:i'],
            'records.*.check_out' => ['nullable', 'date_format:H:i'],
            'records.*.overtime_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
        ]);

        // §26 hardening: a request covering a whole roster used to run with no transaction -- an
        // out-of-branch employee_id partway through (abort_unless) would leave everyone marked before it
        // saved, everyone after it silently unmarked, with no sign anything was incomplete.
        [$created, $skipped] = DB::transaction(function () use ($validated, $user) {
            $created = 0;
            $skipped = 0;
            foreach ($validated['records'] as $record) {
                $employee = Employee::findOrFail($record['employee_id']);
                abort_unless($user->isOrgWide() || $employee->branch_id === $user->branch_id, 403);

                if (StaffAttendance::where('employee_id', $employee->id)->whereDate('date', $validated['date'])->exists()) {
                    $skipped++;

                    continue;
                }

                StaffAttendance::create([
                    'employee_id' => $employee->id,
                    'date' => $validated['date'],
                    'status' => $record['status'],
                    'check_in' => $record['check_in'] ?? null,
                    'check_out' => $record['check_out'] ?? null,
                    'overtime_minutes' => $record['overtime_minutes'] ?? null,
                    'recorded_by' => $user->id,
                ]);
                $created++;
            }

            return [$created, $skipped];
        });

        $message = $skipped > 0
            ? "Attendance recorded for {$created} ({$skipped} already had a record for this day -- use a correction to change those)."
            : "Attendance recorded for {$created}.";

        return back()->with('success', $message);
    }

    public function requestCorrection(Request $request, StaffAttendance $staffAttendance): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('staff-attendance-manage'), 403);
        abort_unless($user->isOrgWide() || $staffAttendance->employee->branch_id === $user->branch_id, 403);
        abort_if($staffAttendance->corrections()->where('status', 'pending')->exists(), 422, 'A correction is already pending for this day.');

        $validated = $request->validate([
            'new_status' => ['required', Rule::in(StaffAttendance::STATUSES)],
            'new_check_in' => ['nullable', 'date_format:H:i'],
            'new_check_out' => ['nullable', 'date_format:H:i'],
            'new_overtime_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $correction = $staffAttendance->corrections()->create([
            ...$validated,
            'requested_by' => $user->id,
        ]);

        $this->notifyOtherApprovers($staffAttendance, $user, new AttendanceCorrectionRequested($correction));

        return back()->with('success', 'Correction requested -- it needs another approver before it takes effect.');
    }

    public function corrections(Request $request): Response
    {
        $user = $request->user();
        abort_unless($this->canView($user), 403);

        $query = StaffAttendanceCorrection::where('status', 'pending')->with('staffAttendance.employee', 'requestedBy');
        if (! $user->isOrgWide()) {
            $query->whereHas('staffAttendance.employee', fn ($q) => $q->where('branch_id', $user->branch_id));
        }

        $corrections = $query->orderBy('created_at')->get()->map(fn (StaffAttendanceCorrection $c) => [
            'id' => $c->id,
            'employee_name' => $c->staffAttendance->employee->full_name,
            'date' => $c->staffAttendance->date->toDateString(),
            'current_status' => $c->staffAttendance->status,
            'new_status' => $c->new_status,
            'new_check_in' => $c->new_check_in,
            'new_check_out' => $c->new_check_out,
            'new_overtime_minutes' => $c->new_overtime_minutes,
            'reason' => $c->reason,
            'requested_by' => $c->requestedBy->name,
            'can_review' => $c->requested_by !== $user->id,
        ]);

        return Inertia::render('Hr/Attendance/Corrections', [
            'corrections' => $corrections,
            'canManage' => $user->can('staff-attendance-manage'),
        ]);
    }

    public function approveCorrection(Request $request, StaffAttendanceCorrection $staffAttendanceCorrection): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('staff-attendance-manage'), 403);
        abort_unless($user->isOrgWide() || $staffAttendanceCorrection->staffAttendance->employee->branch_id === $user->branch_id, 403);
        abort_unless($staffAttendanceCorrection->status === 'pending', 422);
        abort_if($staffAttendanceCorrection->requested_by === $user->id, 403, 'You cannot approve a correction you requested yourself.');

        $staffAttendanceCorrection->staffAttendance->update([
            'status' => $staffAttendanceCorrection->new_status,
            'check_in' => $staffAttendanceCorrection->new_check_in,
            'check_out' => $staffAttendanceCorrection->new_check_out,
            'overtime_minutes' => $staffAttendanceCorrection->new_overtime_minutes,
        ]);
        $staffAttendanceCorrection->update(['status' => 'approved', 'reviewed_by' => $user->id, 'reviewed_at' => now()]);

        $staffAttendanceCorrection->requestedBy->notify(new AttendanceCorrectionReviewed($staffAttendanceCorrection));

        return back()->with('success', 'Correction approved.');
    }

    public function rejectCorrection(Request $request, StaffAttendanceCorrection $staffAttendanceCorrection): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('staff-attendance-manage'), 403);
        abort_unless($user->isOrgWide() || $staffAttendanceCorrection->staffAttendance->employee->branch_id === $user->branch_id, 403);
        abort_unless($staffAttendanceCorrection->status === 'pending', 422);
        abort_if($staffAttendanceCorrection->requested_by === $user->id, 403, 'You cannot reject a correction you requested yourself.');

        $staffAttendanceCorrection->update(['status' => 'rejected', 'reviewed_by' => $user->id, 'reviewed_at' => now()]);

        $staffAttendanceCorrection->requestedBy->notify(new AttendanceCorrectionReviewed($staffAttendanceCorrection));

        return back()->with('success', 'Correction rejected.');
    }

    private function notifyOtherApprovers(StaffAttendance $attendance, User $requester, $notification): void
    {
        $employee = $attendance->employee;
        User::where('school_id', $employee->school_id)
            ->where('id', '!=', $requester->id)
            ->where(fn ($q) => $q->where('branch_id', $employee->branch_id)->orWhereNull('branch_id'))
            ->get()
            ->filter(fn (User $u) => $u->can('staff-attendance-manage'))
            ->each(fn (User $u) => $u->notify($notification));
    }
}
