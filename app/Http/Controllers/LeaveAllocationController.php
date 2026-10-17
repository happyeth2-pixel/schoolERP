<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\LeaveAllocation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LeaveAllocationController extends Controller
{
    private function assertEmployeeAccess(User $user, Employee $employee): void
    {
        abort_unless($employee->school_id === $user->school_id, 403);
        abort_unless($user->isOrgWide() || $employee->branch_id === $user->branch_id, 403);
    }

    public function store(Request $request, Employee $employee): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('leave-approve'), 403);
        $this->assertEmployeeAccess($user, $employee);

        $validated = $request->validate([
            'leave_type_id' => ['required', 'integer', Rule::exists('leave_types', 'id')->where('school_id', $user->school_id)],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'allocated_days' => ['required', 'numeric', 'min:0', 'max:365'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $exists = LeaveAllocation::where('employee_id', $employee->id)
            ->where('leave_type_id', $validated['leave_type_id'])->where('year', $validated['year'])->exists();
        abort_if($exists, 422, 'An allocation for this leave type and year already exists -- change it instead.');

        $employee->leaveAllocations()->create([...$validated, 'created_by' => $user->id]);

        return back()->with('success', 'Leave allocation added.');
    }

    public function update(Request $request, LeaveAllocation $leaveAllocation): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('leave-approve'), 403);
        $this->assertEmployeeAccess($user, $leaveAllocation->employee);

        $validated = $request->validate([
            'allocated_days' => ['required', 'numeric', 'min:0', 'max:365'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $leaveAllocation->update($validated);

        return back()->with('success', 'Leave allocation updated.');
    }

    public function destroy(Request $request, LeaveAllocation $leaveAllocation): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('leave-approve'), 403);
        $this->assertEmployeeAccess($user, $leaveAllocation->employee);

        $leaveAllocation->delete();

        return back()->with('success', 'Leave allocation removed.');
    }
}
