<?php

namespace App\Http\Controllers;

use App\Models\Section;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SectionController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $request->user()->can('manage-classes') || abort(403);
        $user = $request->user();

        $validated = $request->validate([
            'branch_id' => ['required', 'integer'],
            'grade_id' => ['required', 'integer'],
            'academic_year_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:50'],
            'capacity' => ['nullable', 'integer', 'min:1'],
        ]);

        $this->assertBranchInScope($user, (int) $validated['branch_id']);
        $this->assertBelongsToSchool($user, 'grade', $validated['grade_id']);
        $this->assertBelongsToSchool($user, 'academicYear', $validated['academic_year_id']);

        Section::create($validated);

        return back()->with('success', 'Section created.');
    }

    public function update(Request $request, Section $section): RedirectResponse
    {
        $request->user()->can('manage-classes') || abort(403);
        $user = $request->user();
        $this->assertBranchInScope($user, $section->branch_id);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:50'],
            'capacity' => ['nullable', 'integer', 'min:1'],
        ]);

        $section->update($validated);

        return back()->with('success', 'Section updated.');
    }

    public function destroy(Request $request, Section $section): RedirectResponse
    {
        $request->user()->can('manage-classes') || abort(403);
        $this->assertBranchInScope($request->user(), $section->branch_id);

        $section->delete();

        return back()->with('success', 'Section removed.');
    }

    private function assertBranchInScope($user, int $branchId): void
    {
        $branch = $user->school->branches()->whereKey($branchId)->first();
        abort_unless($branch, 403);

        if (! $user->isOrgWide() && $user->branch_id !== $branchId) {
            abort(403, 'You can only manage sections in your own branch.');
        }
    }

    private function assertBelongsToSchool($user, string $relation, int $id): void
    {
        $exists = match ($relation) {
            'grade' => $user->school->grades()->whereKey($id)->exists(),
            'academicYear' => $user->school->academicYears()->whereKey($id)->exists(),
        };

        abort_unless($exists, 403);
    }
}
