<?php

namespace App\Http\Controllers;

use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** A school's own configurable leave categories -- see LeaveType's own docblock. Managing types (and, by the
 * same token, allocations) is gated on `leave-approve`, the "HR manages leave" permission, the same way
 * Department/Position management rides on `employee-manage` rather than a separate permission each. */
class LeaveTypeController extends Controller
{
    private function canView(User $user): bool
    {
        return $user->can('leave-view') || $user->can('leave-approve') || $user->can('leave-request') || $user->hasRole('auditor');
    }

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($this->canView($user), 403);

        return Inertia::render('Hr/LeaveTypes/Index', [
            'leaveTypes' => LeaveType::where('school_id', $user->school_id)->withCount(['allocations', 'requests'])
                ->orderBy('name')->get()->map(fn (LeaveType $t) => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'default_annual_days' => $t->default_annual_days !== null ? (float) $t->default_annual_days : null,
                    'requires_document' => $t->requires_document,
                    'notes' => $t->notes,
                    'allocations_count' => $t->allocations_count,
                    'requests_count' => $t->requests_count,
                ]),
            'canManage' => $user->can('leave-approve'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('leave-approve'), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('leave_types')->where('school_id', $user->school_id)],
            'default_annual_days' => ['nullable', 'numeric', 'min:0', 'max:365'],
            'requires_document' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        LeaveType::create(['school_id' => $user->school_id, ...$validated]);

        return back()->with('success', 'Leave type added.');
    }

    public function update(Request $request, LeaveType $leaveType): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('leave-approve'), 403);
        abort_unless($leaveType->school_id === $user->school_id, 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('leave_types')->where('school_id', $user->school_id)->ignore($leaveType->id)],
            'default_annual_days' => ['nullable', 'numeric', 'min:0', 'max:365'],
            'requires_document' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $leaveType->update($validated);

        return back()->with('success', 'Leave type updated.');
    }

    public function destroy(Request $request, LeaveType $leaveType): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('leave-approve'), 403);
        abort_unless($leaveType->school_id === $user->school_id, 403);

        try {
            $leaveType->delete();
        } catch (\App\Exceptions\DeletionBlockedException $e) {
            return back()->withErrors(['name' => $e->getMessage()]);
        }

        return back()->with('success', 'Leave type removed.');
    }
}
