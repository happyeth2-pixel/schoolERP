<?php

namespace App\Http\Controllers;

use App\Models\Section;
use App\Models\TeachingAssignment;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TeachingAssignmentController extends Controller
{
    public function index(Request $request): Response
    {
        $request->user()->can('manage-teachers') || abort(403);
        $user = $request->user();

        $sectionsQuery = Section::query()
            ->whereIn('branch_id', $user->school->branches()->pluck('id'))
            ->whereHas('academicYear', fn ($q) => $q->where('is_current', true))
            ->with(['grade', 'branch', 'classTeacher', 'teachingAssignments.subject', 'teachingAssignments.teacher']);

        if (! $user->isOrgWide()) {
            $sectionsQuery->where('branch_id', $user->branch_id);
        }

        $teachersQuery = User::where('school_id', $user->school_id)
            ->whereHas('roles', fn ($q) => $q->where('name', 'teacher'));
        if (! $user->isOrgWide()) {
            $teachersQuery->where('branch_id', $user->branch_id);
        }

        return Inertia::render('TeachingAssignments/Index', [
            'sections' => $sectionsQuery->orderBy('grade_id')->get()->map(fn (Section $s) => [
                'id' => $s->id,
                'label' => "{$s->grade->name} {$s->name} ({$s->branch->name})",
                'class_teacher' => $s->classTeacher?->only(['id', 'name']),
                'assignments' => $s->teachingAssignments->map(fn (TeachingAssignment $a) => [
                    'id' => $a->id,
                    'subject_id' => $a->subject_id,
                    'subject' => $a->subject->name,
                    'teacher' => $a->teacher->name,
                ]),
            ]),
            'teachers' => $teachersQuery->orderBy('name')->get(['id', 'name']),
            'subjects' => $user->school->subjects()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->user()->can('manage-teachers') || abort(403);
        $user = $request->user();

        $validated = $request->validate([
            'section_id' => ['required', 'integer'],
            'subject_id' => ['required', 'integer'],
            'teacher_id' => ['required', 'integer'],
        ]);

        $section = Section::whereKey($validated['section_id'])->firstOrFail();
        $this->assertBranchInScope($user, $section->branch_id);

        $teacher = User::whereKey($validated['teacher_id'])
            ->where('school_id', $user->school_id)
            ->whereHas('roles', fn ($q) => $q->where('name', 'teacher'))
            ->firstOrFail();
        abort_unless($teacher->branch_id === $section->branch_id, 422, 'Teacher must belong to the same branch as the section.');

        abort_unless(
            $user->school->subjects()->whereKey($validated['subject_id'])->exists(),
            422
        );

        TeachingAssignment::updateOrCreate(
            ['section_id' => $section->id, 'subject_id' => $validated['subject_id']],
            ['teacher_id' => $teacher->id]
        );

        return back()->with('success', 'Teaching assignment saved.');
    }

    public function destroy(Request $request, TeachingAssignment $teachingAssignment): RedirectResponse
    {
        $request->user()->can('manage-teachers') || abort(403);
        $this->assertBranchInScope($request->user(), $teachingAssignment->section->branch_id);

        $teachingAssignment->delete();

        return back()->with('success', 'Teaching assignment removed.');
    }

    public function setClassTeacher(Request $request, Section $section): RedirectResponse
    {
        $request->user()->can('manage-classes') || abort(403);
        $this->assertBranchInScope($request->user(), $section->branch_id);

        $validated = $request->validate([
            'class_teacher_id' => ['nullable', 'integer'],
        ]);

        if (! empty($validated['class_teacher_id'])) {
            $teacher = User::whereKey($validated['class_teacher_id'])
                ->where('branch_id', $section->branch_id)
                ->whereHas('roles', fn ($q) => $q->where('name', 'teacher'))
                ->firstOrFail();
            $section->update(['class_teacher_id' => $teacher->id]);
        } else {
            $section->update(['class_teacher_id' => null]);
        }

        return back()->with('success', 'Class teacher updated.');
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
