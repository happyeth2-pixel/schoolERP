<?php

namespace App\Http\Controllers;

use App\Models\Section;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SectionController extends Controller
{
    public function index(Request $request): Response
    {
        $request->user()->canViewOrManage('manage-classes') || abort(403);
        $user = $request->user();
        $school = $user->school;

        $sectionsQuery = Section::query()
            ->whereIn('branch_id', $school->branches()->pluck('id'))
            ->with(['branch', 'grade', 'academicYear']);

        if (! $user->isOrgWide()) {
            $sectionsQuery->where('branch_id', $user->branch_id);
        }

        return Inertia::render('Sections/Index', [
            'sections' => $sectionsQuery->orderBy('grade_id')->orderBy('name')->get(),
            'branches' => $user->isOrgWide()
                ? $school->branches()->orderBy('name')->get()
                : $school->branches()->where('id', $user->branch_id)->get(),
            'grades' => $school->grades()->orderBy('sort_order')->get(),
            'academicYears' => $school->academicYears()->orderByDesc('start_date')->get(),
        ]);
    }

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
