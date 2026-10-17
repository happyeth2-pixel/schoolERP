<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DepartmentController extends Controller
{
    private function canView(User $user): bool
    {
        return $user->can('employee-view') || $user->can('employee-manage') || $user->hasRole('auditor');
    }

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($this->canView($user), 403);

        $query = Department::where('school_id', $user->school_id)->with('branch', 'manager')->withCount(['employees', 'positions']);
        if (! $user->isOrgWide()) {
            $query->where(fn ($q) => $q->where('branch_id', $user->branch_id)->orWhereNull('branch_id'));
        }

        return Inertia::render('Hr/Departments/Index', [
            'departments' => $query->orderBy('name')->get()->map(fn (Department $d) => [
                'id' => $d->id,
                'name' => $d->name,
                'branch' => $d->branch?->name,
                'manager' => $d->manager?->full_name,
                'employees_count' => $d->employees_count,
                'positions_count' => $d->positions_count,
                'notes' => $d->notes,
            ]),
            'branches' => $user->isOrgWide() ? $user->school->branches()->get(['id', 'name']) : [],
            'employees' => Employee::where('school_id', $user->school_id)->orderBy('full_name')->get(['id', 'full_name']),
            'canManage' => $user->can('employee-manage'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('employee-manage'), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where('school_id', $user->school_id)],
            'notes' => ['nullable', 'string'],
        ]);

        if (! $user->isOrgWide()) {
            $validated['branch_id'] = $user->branch_id;
        }

        Department::create([
            'school_id' => $user->school_id,
            'branch_id' => $validated['branch_id'] ?? null,
            'name' => $validated['name'],
            'notes' => $validated['notes'] ?? null,
        ]);

        return back()->with('success', 'Department added.');
    }

    public function update(Request $request, Department $department): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('employee-manage'), 403);
        abort_unless($department->school_id === $user->school_id, 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'manager_employee_id' => ['nullable', 'integer', Rule::exists('employees', 'id')->where('school_id', $user->school_id)],
            'notes' => ['nullable', 'string'],
        ]);

        $department->update($validated);

        return back()->with('success', 'Department updated.');
    }

    public function destroy(Request $request, Department $department): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('employee-manage'), 403);
        abort_unless($department->school_id === $user->school_id, 403);

        try {
            $department->delete();
        } catch (\App\Exceptions\DeletionBlockedException $e) {
            return back()->withErrors(['name' => $e->getMessage()]);
        }

        return back()->with('success', 'Department removed.');
    }
}
