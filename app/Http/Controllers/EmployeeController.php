<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\NumberSequence;
use App\Models\Position;
use App\Models\User;
use App\Rules\SafeUploadRule;
use App\Support\SafeUpload;
use App\Support\SchoolCalendar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The HR employee record -- deliberately separate from the login account (`App\Http\Controllers\
 * StaffController`). Nothing here creates, changes, or removes a `User`'s account, role, or permissions;
 * linking an employee to an existing account only connects two already-independent records, it never
 * grants access by itself (access is still only ever granted the existing way, via the Staff wizard).
 */
class EmployeeController extends Controller
{
    /** Staff tiers that can plausibly be a school employee -- mirrors StaffController::STAFF_ROLES, since
     * parent/student accounts are never employees. */
    private const STAFF_ROLES = ['owner', 'branch_admin', 'teacher', 'accountant', 'auditor'];

    private function canView(User $user): bool
    {
        return $user->can('employee-view') || $user->can('employee-manage') || $user->hasRole('auditor');
    }

    private function scoped(Request $request)
    {
        $user = $request->user();
        $query = Employee::where('school_id', $user->school_id)->with('department', 'position', 'branch', 'user');
        if (! $user->isOrgWide()) {
            $query->where('branch_id', $user->branch_id);
        }

        return $query;
    }

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($this->canView($user), 403);

        $employees = $this->scoped($request)->orderBy('full_name')->get()->map(fn (Employee $e) => [
            'id' => $e->id,
            'employee_number' => $e->employee_number,
            'full_name' => $e->full_name,
            'photo_url' => $e->photo_path ? Storage::disk('public')->url($e->photo_path) : null,
            'department' => $e->department?->name,
            'position' => $e->position?->title,
            'branch' => $e->branch?->name,
            'employment_status' => $e->employment_status,
            'employment_type' => $e->employment_type,
            'has_account' => $e->user_id !== null,
        ]);

        return Inertia::render('Hr/Employees/Index', [
            'employees' => $employees,
            'canManage' => $user->can('employee-manage'),
        ]);
    }

    public function create(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->can('employee-manage'), 403);

        return Inertia::render('Hr/Employees/Create', $this->formProps($user));
    }

    private function formProps(User $user, ?Employee $employee = null): array
    {
        $deptQuery = Department::where('school_id', $user->school_id);
        $usersQuery = User::where('school_id', $user->school_id)->whereHas('roles', fn ($q) => $q->whereIn('name', self::STAFF_ROLES))->whereDoesntHave('employee');
        if (! $user->isOrgWide()) {
            $deptQuery->where(fn ($q) => $q->where('branch_id', $user->branch_id)->orWhereNull('branch_id'));
            $usersQuery->where('branch_id', $user->branch_id);
        }
        if ($employee?->user_id) {
            // editing an already-linked employee: include their own linked user in the pickable list
            $usersQuery = $usersQuery->orWhere('id', $employee->user_id);
        }

        return [
            'departments' => $deptQuery->orderBy('name')->get(['id', 'name']),
            'positions' => Position::where('school_id', $user->school_id)->orderBy('title')->get(['id', 'title', 'department_id']),
            'branches' => $user->isOrgWide() ? $user->school->branches()->get(['id', 'name']) : [],
            'linkableUsers' => $usersQuery->orderBy('name')->get(['id', 'name', 'phone', 'branch_id']),
            'employmentStatuses' => Employee::EMPLOYMENT_STATUSES,
            'employmentTypes' => Employee::EMPLOYMENT_TYPES,
        ];
    }

    private function nextEmployeeNumber(int $schoolId): string
    {
        $year = (int) date('Y');
        $prefix = "EMP-{$year}-";

        $n = NumberSequence::next($schoolId, 'employee', $year, fn () => Employee::where('school_id', $schoolId)
            ->where('employee_number', 'like', "{$prefix}%")
            ->pluck('employee_number')
            ->map(fn ($x) => (int) substr($x, strlen($prefix)))
            ->max() ?? 0);

        return $prefix.str_pad((string) $n, 6, '0', STR_PAD_LEFT);
    }

    /** Same shape as StudentController::ageToDateOfBirth() -- registration asks for an age in whole years,
     * not an exact birthdate, converted to an approximate date_of_birth (today minus N years) before
     * validation so every existing date_of_birth-based rule and Employee::age() keep working unchanged. */
    private function ageToDateOfBirth(Request $request): void
    {
        if ($request->filled('age')) {
            $request->merge(['date_of_birth' => SchoolCalendar::today()->subYears((int) $request->input('age'))->toDateString()]);
        }
    }

    private function rules(User $user, ?Employee $employee = null): array
    {
        return [
            'full_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'emergency_contact_name' => ['nullable', 'string', 'max:255'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:30'],
            'gender' => ['nullable', Rule::in(['male', 'female'])],
            'age' => ['nullable', 'integer', 'min:0', 'max:80'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'hire_date' => ['required', 'date'],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')->where('school_id', $user->school_id)],
            'position_id' => ['nullable', 'integer', Rule::exists('positions', 'id')->where('school_id', $user->school_id)],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where('school_id', $user->school_id)],
            'employment_status' => ['required', Rule::in(Employee::EMPLOYMENT_STATUSES)],
            'employment_type' => ['required', Rule::in(Employee::EMPLOYMENT_TYPES)],
            'qualifications' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('school_id', $user->school_id)],
            'photo' => ['nullable', 'file', 'max:2048', new SafeUploadRule(['jpg', 'png'])],
        ];
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('employee-manage'), 403);

        $this->ageToDateOfBirth($request);
        $validated = $request->validate($this->rules($user));

        if (! $user->isOrgWide()) {
            $validated['branch_id'] = $user->branch_id;
        }

        if (! empty($validated['user_id'])) {
            abort_unless(Employee::where('user_id', $validated['user_id'])->doesntExist(), 422, 'That account is already linked to another employee.');
        }

        $employee = DB::transaction(function () use ($user, $validated, $request) {
            $employee = Employee::create([
                'school_id' => $user->school_id,
                'branch_id' => $validated['branch_id'] ?? null,
                'user_id' => $validated['user_id'] ?? null,
                'employee_number' => $this->nextEmployeeNumber($user->school_id),
                'full_name' => $validated['full_name'],
                'phone' => $validated['phone'],
                'email' => $validated['email'] ?? null,
                'address' => $validated['address'] ?? null,
                'emergency_contact_name' => $validated['emergency_contact_name'] ?? null,
                'emergency_contact_phone' => $validated['emergency_contact_phone'] ?? null,
                'gender' => $validated['gender'] ?? null,
                'date_of_birth' => $validated['date_of_birth'] ?? null,
                'hire_date' => $validated['hire_date'],
                'department_id' => $validated['department_id'] ?? null,
                'position_id' => $validated['position_id'] ?? null,
                'employment_status' => $validated['employment_status'],
                'employment_type' => $validated['employment_type'],
                'qualifications' => $validated['qualifications'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'created_by' => $user->id,
            ]);

            if ($request->hasFile('photo')) {
                $employee->update(['photo_path' => SafeUpload::storePublicImage($request->file('photo'), 'employee-photos', 800)]);
            }

            return $employee;
        });

        return redirect()->route('employees.show', $employee)->with('success', 'Employee added.');
    }

    public function show(Request $request, Employee $employee): Response
    {
        $user = $request->user();
        abort_unless($this->canView($user), 403);
        abort_unless($employee->school_id === $user->school_id, 403);
        abort_unless($user->isOrgWide() || $employee->branch_id === $user->branch_id, 403);

        $employee->load('department', 'position', 'branch', 'user', 'createdBy', 'contracts');
        $canViewContracts = $user->can('contract-view') || $user->can('contract-manage') || $user->hasRole('auditor');
        $canViewLeave = $user->can('leave-view') || $user->can('leave-approve') || $user->hasRole('auditor');
        $canViewPerformance = $user->can('performance-view') || $user->can('performance-manage') || $user->hasRole('auditor');
        $canViewPayroll = $user->can('payroll-view') || $user->can('payroll-manage') || $user->hasRole('auditor');
        $leaveYear = (int) now()->year;

        return Inertia::render('Hr/Employees/Show', [
            'employee' => [
                'id' => $employee->id,
                'employee_number' => $employee->employee_number,
                'full_name' => $employee->full_name,
                'photo_url' => $employee->photo_path ? Storage::disk('public')->url($employee->photo_path) : null,
                'phone' => $employee->phone,
                'email' => $employee->email,
                'address' => $employee->address,
                'emergency_contact_name' => $employee->emergency_contact_name,
                'emergency_contact_phone' => $employee->emergency_contact_phone,
                'gender' => $employee->gender,
                'age' => $employee->age(),
                'hire_date' => $employee->hire_date->toDateString(),
                'department' => $employee->department?->name,
                'position' => $employee->position?->title,
                'branch' => $employee->branch?->name,
                'employment_status' => $employee->employment_status,
                'employment_type' => $employee->employment_type,
                'qualifications' => $employee->qualifications,
                'notes' => $employee->notes,
                'linked_user' => $employee->user ? ['id' => $employee->user->id, 'name' => $employee->user->name, 'phone' => $employee->user->phone] : null,
                'created_by' => $employee->createdBy->name,
            ],
            'canManage' => $user->can('employee-manage'),
            'canViewContracts' => $canViewContracts,
            'canManageContracts' => $user->can('contract-manage'),
            'contracts' => $canViewContracts ? $employee->contracts->map(fn (EmploymentContract $c) => [
                'id' => $c->id,
                'contract_type' => $c->contract_type,
                'start_date' => $c->start_date->toDateString(),
                'end_date' => $c->end_date?->toDateString(),
                'status' => $c->status,
                'salary_reference' => $c->salary_reference !== null ? (float) $c->salary_reference : null,
                'working_schedule' => $c->working_schedule,
                'renewal_date' => $c->renewal_date?->toDateString(),
                'notes' => $c->notes,
                'has_document' => $c->document_path !== null,
                'can_remove' => $c->status === 'draft',
            ]) : [],
            'canViewLeave' => $canViewLeave,
            'canManageLeave' => $user->can('leave-approve'),
            'leaveTypes' => $canViewLeave ? \App\Models\LeaveType::where('school_id', $user->school_id)->orderBy('name')->get(['id', 'name', 'requires_document']) : [],
            'leaveAllocations' => $canViewLeave ? $employee->leaveAllocations()->where('year', $leaveYear)->with('leaveType')->get()->map(fn (\App\Models\LeaveAllocation $a) => [
                'id' => $a->id,
                'leave_type' => $a->leaveType->name,
                'year' => $a->year,
                'allocated_days' => (float) $a->allocated_days,
                'used_days' => $a->usedDays(),
                'balance' => $a->balance(),
            ]) : [],
            'leaveRequests' => $canViewLeave ? $employee->leaveRequests->load('leaveType')->map(fn (\App\Models\LeaveRequest $r) => [
                'id' => $r->id,
                'leave_type' => $r->leaveType->name,
                'start_date' => $r->start_date->toDateString(),
                'end_date' => $r->end_date->toDateString(),
                'days_count' => (float) $r->days_count,
                'status' => $r->status,
                'has_document' => $r->document_path !== null,
                'can_remove' => $r->status === 'draft',
            ]) : [],
            'canViewPerformance' => $canViewPerformance,
            // Never show write/finalize actions on your OWN record -- a self-review attempt is always
            // refused server-side anyway, but a button that can only ever fail silently should not be shown.
            'canManagePerformance' => $user->can('performance-manage') && $employee->user_id !== $user->id,
            'performanceCycles' => $canViewPerformance ? \App\Models\PerformanceReviewCycle::where('school_id', $user->school_id)->orderByDesc('start_date')->get(['id', 'name']) : [],
            'performanceReviews' => $canViewPerformance ? $employee->performanceReviews->load('cycle')->map(fn (\App\Models\PerformanceReview $r) => [
                'id' => $r->id,
                'cycle_id' => $r->cycle_id,
                'cycle' => $r->cycle->name,
                'rating' => $r->rating,
                'comments' => $r->comments,
                'status' => $r->status,
                'can_remove' => $r->status === 'draft',
            ]) : [],
            'canViewPayroll' => $canViewPayroll,
            'payslips' => $canViewPayroll ? $employee->payslips()->whereHas('payrollRun', fn ($q) => $q->where('status', 'finalized'))->with('payrollRun')->get()->map(fn (\App\Models\Payslip $p) => [
                'id' => $p->id,
                'period' => $p->payrollRun->label(),
                'basic_salary' => (float) $p->basic_salary,
                'income_tax' => (float) $p->income_tax,
                'employee_pension' => (float) $p->employee_pension,
                'net_pay' => (float) $p->net_pay,
            ]) : [],
        ]);
    }

    public function edit(Request $request, Employee $employee): Response
    {
        $user = $request->user();
        abort_unless($user->can('employee-manage'), 403);
        abort_unless($employee->school_id === $user->school_id, 403);
        abort_unless($user->isOrgWide() || $employee->branch_id === $user->branch_id, 403);

        return Inertia::render('Hr/Employees/Edit', array_merge($this->formProps($user, $employee), [
            'employee' => [
                'id' => $employee->id,
                'full_name' => $employee->full_name,
                'photo_url' => $employee->photo_path ? Storage::disk('public')->url($employee->photo_path) : null,
                'phone' => $employee->phone,
                'email' => $employee->email,
                'address' => $employee->address,
                'emergency_contact_name' => $employee->emergency_contact_name,
                'emergency_contact_phone' => $employee->emergency_contact_phone,
                'gender' => $employee->gender,
                'age' => $employee->age(),
                'hire_date' => $employee->hire_date->toDateString(),
                'department_id' => $employee->department_id,
                'position_id' => $employee->position_id,
                'branch_id' => $employee->branch_id,
                'employment_status' => $employee->employment_status,
                'employment_type' => $employee->employment_type,
                'qualifications' => $employee->qualifications,
                'notes' => $employee->notes,
                'user_id' => $employee->user_id,
            ],
        ]));
    }

    public function update(Request $request, Employee $employee): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('employee-manage'), 403);
        abort_unless($employee->school_id === $user->school_id, 403);
        abort_unless($user->isOrgWide() || $employee->branch_id === $user->branch_id, 403);

        $this->ageToDateOfBirth($request);
        $validated = $request->validate($this->rules($user, $employee));

        if (! $user->isOrgWide()) {
            $validated['branch_id'] = $employee->branch_id;
        }

        $oldUserId = $employee->user_id;
        $newUserId = $validated['user_id'] ?? null;
        if ($newUserId && $newUserId !== $oldUserId) {
            abort_unless(Employee::where('user_id', $newUserId)->where('id', '!=', $employee->id)->doesntExist(), 422, 'That account is already linked to another employee.');
        }

        DB::transaction(function () use ($employee, $validated, $request, $newUserId) {
            // The Audited trait's automatic `updated` hook picks up this change and logs it as
            // employee.account_linked/account_unlinked/status_changed/updated (see Employee::auditAction()),
            // with a full before/after field diff -- no separate manual log entry needed here.
            $employee->update([
                'full_name' => $validated['full_name'],
                'phone' => $validated['phone'],
                'email' => $validated['email'] ?? null,
                'address' => $validated['address'] ?? null,
                'emergency_contact_name' => $validated['emergency_contact_name'] ?? null,
                'emergency_contact_phone' => $validated['emergency_contact_phone'] ?? null,
                'gender' => $validated['gender'] ?? null,
                'date_of_birth' => $validated['date_of_birth'] ?? null,
                'hire_date' => $validated['hire_date'],
                'department_id' => $validated['department_id'] ?? null,
                'position_id' => $validated['position_id'] ?? null,
                'branch_id' => $validated['branch_id'] ?? null,
                'employment_status' => $validated['employment_status'],
                'employment_type' => $validated['employment_type'],
                'qualifications' => $validated['qualifications'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'user_id' => $newUserId,
            ]);

            if ($request->hasFile('photo')) {
                $old = $employee->photo_path;
                $employee->update(['photo_path' => SafeUpload::storePublicImage($request->file('photo'), 'employee-photos', 800)]);
                if ($old) {
                    Storage::disk('public')->delete($old);
                }
            }
        });

        return redirect()->route('employees.show', $employee)->with('success', 'Employee updated.');
    }
}
