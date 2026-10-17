<?php

namespace App\Http\Controllers;

use App\Services\SectionCapacity;
use App\Models\Section;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SectionController extends Controller
{
    /** "Add a section" as three short steps (where, name and size, check). */
    public function create(Request $request): Response
    {
        $request->user()->can('manage-classes') || abort(403);
        $user = $request->user();
        $school = $user->school;

        $existing = Section::query()
            ->whereIn('branch_id', $school->branches()->pluck('id'))
            ->when(! $user->isOrgWide(), fn ($q) => $q->where('branch_id', $user->branch_id))
            ->get(['id', 'branch_id', 'grade_id', 'academic_year_id', 'name', 'capacity']);

        return Inertia::render('AcademicStructure/SectionWizard', [
            'grades' => $school->grades()->orderBy('sort_order')->get(['id', 'name']),
            'branches' => $user->isOrgWide()
                ? $school->branches()->orderBy('name')->get(['id', 'name'])
                : $school->branches()->where('id', $user->branch_id)->get(['id', 'name']),
            'academicYears' => $school->academicYears()->orderByDesc('start_date')->get(['id', 'name', 'is_current']),
            'existing' => $existing,
            'presetGrade' => $request->integer('grade') ?: null,
            'saved' => session('section_saved'),
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

        $this->refuseDuplicate($validated, null);

        try {
            $section = Section::create($validated);
        } catch (UniqueConstraintViolationException) {
            $this->refuseDuplicate($validated, null, true);
        }

        return back()->with('success', 'Section created.')->with('section_saved', [
            'id' => $section->id,
            'grade' => $section->grade->name,
            'grade_id' => $section->grade_id,
            'name' => $section->name,
            'branch' => $section->branch->name,
            'academic_year' => $section->academicYear->name,
            'capacity' => $section->capacity,
        ]);
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

        if (! empty($validated['capacity']) && $validated['capacity'] < SectionCapacity::occupied($section)) {
            return back()->withErrors(['capacity' => 'This section already has '.SectionCapacity::occupied($section).' active students, so its capacity cannot be lower than that.']);
        }

        $scope = ['branch_id' => $section->branch_id, 'grade_id' => $section->grade_id, 'academic_year_id' => $section->academic_year_id];
        $this->refuseDuplicate([...$validated, ...$scope], $section->id);

        try {
            $section->update($validated);
        } catch (UniqueConstraintViolationException) {
            $this->refuseDuplicate([...$validated, ...$scope], $section->id, true);
        }

        return back()->with('success', 'Section updated.');
    }

    public function destroy(Request $request, Section $section): RedirectResponse
    {
        $request->user()->can('manage-classes') || abort(403);
        $this->assertBranchInScope($request->user(), $section->branch_id);

        $section->delete();

        return back()->with('success', 'Section removed.');
    }

    /** The database already allows one section of a given name per branch, grade and year; this says so in words. */
    private function refuseDuplicate(array $v, ?int $ignoreId, bool $force = false): void
    {
        $clash = $force || Section::where('branch_id', $v['branch_id'])->where('grade_id', $v['grade_id'])
            ->where('academic_year_id', $v['academic_year_id'])->where('name', $v['name'])
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists();

        if ($clash) {
            throw ValidationException::withMessages(['name' => "There is already a section called {$v['name']} for this grade, branch and school year. Choose a different name."]);
        }
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
