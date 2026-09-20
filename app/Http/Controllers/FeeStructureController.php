<?php

namespace App\Http\Controllers;

use App\Models\FeeStructure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FeeStructureController extends Controller
{
    public function index(Request $request): Response
    {
        $request->user()->can('manage-fee-structures') || abort(403);
        $user = $request->user();

        $query = FeeStructure::whereIn('branch_id', $user->school->branches()->pluck('id'))
            ->with(['branch', 'grade', 'academicYear']);

        if (! $user->isOrgWide()) {
            $query->where('branch_id', $user->branch_id);
        }

        return Inertia::render('FeeStructures/Index', [
            'feeStructures' => $query->orderBy('grade_id')->get(),
            'branches' => $user->isOrgWide()
                ? $user->school->branches()->orderBy('name')->get(['id', 'name'])
                : $user->school->branches()->where('id', $user->branch_id)->get(['id', 'name']),
            'grades' => $user->school->grades()->orderBy('sort_order')->get(['id', 'name']),
            'academicYears' => $user->school->academicYears()->orderByDesc('start_date')->get(['id', 'name', 'is_current']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->user()->can('manage-fee-structures') || abort(403);
        $user = $request->user();

        $validated = $request->validate([
            'branch_id' => ['required', 'integer'],
            'grade_id' => ['required', 'integer'],
            'academic_year_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0'],
            'frequency' => ['required', 'in:annual,termly,monthly'],
        ]);

        $this->assertBranchInScope($user, (int) $validated['branch_id']);
        abort_unless($user->school->grades()->whereKey($validated['grade_id'])->exists(), 403);
        abort_unless($user->school->academicYears()->whereKey($validated['academic_year_id'])->exists(), 403);

        FeeStructure::create($validated);

        return back()->with('success', 'Fee item added.');
    }

    public function update(Request $request, FeeStructure $feeStructure): RedirectResponse
    {
        $request->user()->can('manage-fee-structures') || abort(403);
        $this->assertBranchInScope($request->user(), $feeStructure->branch_id);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0'],
            'frequency' => ['required', 'in:annual,termly,monthly'],
        ]);

        $feeStructure->update($validated);

        return back()->with('success', 'Fee item updated.');
    }

    public function destroy(Request $request, FeeStructure $feeStructure): RedirectResponse
    {
        $request->user()->can('manage-fee-structures') || abort(403);
        $this->assertBranchInScope($request->user(), $feeStructure->branch_id);

        $feeStructure->delete();

        return back()->with('success', 'Fee item removed.');
    }

    private function assertBranchInScope($user, int $branchId): void
    {
        $branch = $user->school->branches()->whereKey($branchId)->first();
        abort_unless($branch, 403);

        if (! $user->isOrgWide()) {
            abort_unless($user->branch_id === $branchId, 403);
        }
    }
}
