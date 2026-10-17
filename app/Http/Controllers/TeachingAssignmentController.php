<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\MarkBatch;
use App\Models\Section;
use App\Models\TeachingAssignment;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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
                'grade' => $s->grade->name,
                'name' => $s->name,
                'branch_id' => $s->branch_id,
                'branch' => $s->branch->name,
                'class_teacher' => $s->classTeacher?->only(['id', 'name']),
                'assignments' => $s->teachingAssignments->sortBy(fn ($a) => $a->subject->name)->values()->map(fn (TeachingAssignment $a) => [
                    'id' => $a->id,
                    'subject_id' => $a->subject_id,
                    'subject' => $a->subject->name,
                    'teacher' => $a->teacher->name,
                ]),
            ]),
            'teachers' => $teachersQuery->orderBy('name')->get(['id', 'name', 'branch_id']),
            'subjects' => $user->school->subjects()->orderBy('name')->get(['id', 'name']),
            'canSetClassTeacher' => $user->can('manage-classes'),
            'focus' => $request->integer('section') ?: null,
            'branchCount' => $user->school->branches()->count(),
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
            'replace' => ['nullable', 'boolean'],
        ]);

        $section = Section::whereKey($validated['section_id'])->firstOrFail();
        $this->assertBranchInScope($user, $section->branch_id);

        $teacher = User::whereKey($validated['teacher_id'])
            ->where('school_id', $user->school_id)
            ->where('status', 'active')
            ->whereHas('roles', fn ($q) => $q->where('name', 'teacher'))
            ->firstOrFail();
        abort_unless($teacher->branch_id === $section->branch_id, 422, 'Teacher must belong to the same branch as the section.');

        abort_unless(
            $user->school->subjects()->whereKey($validated['subject_id'])->exists(),
            422
        );

        // The identity of an assignment is (section, subject): the section already pins the academic year
        // and branch, and a subject has exactly ONE teacher per section (unique key in the database).
        // Asking for the same teacher again is a no-op; a different teacher is a deliberate replacement.
        try {
            $message = DB::transaction(function () use ($section, $teacher, $validated, $user) {
                $existing = TeachingAssignment::where('section_id', $section->id)
                    ->where('subject_id', $validated['subject_id'])
                    ->lockForUpdate()
                    ->first();

                if (! $existing) {
                    TeachingAssignment::create(['section_id' => $section->id, 'subject_id' => $validated['subject_id'], 'teacher_id' => $teacher->id]);

                    return 'Teaching assignment saved.';
                }

                if ($existing->teacher_id === $teacher->id) {
                    throw ValidationException::withMessages(['teacher_id' => "{$teacher->name} already teaches this subject in this section."]);
                }

                if (! ($validated['replace'] ?? false)) {
                    throw ValidationException::withMessages(['teacher_id' => "{$existing->teacher->name} already teaches this subject in this section. Confirm the replacement to give it to {$teacher->name}."]);
                }

                // Marks not yet submitted move with the subject to the new teacher; anything already submitted,
                // approved or published stays attributed to the teacher who entered it.
                $moved = MarkBatch::where('section_id', $section->id)->where('subject_id', $validated['subject_id'])
                    ->where('status', 'draft')->where('teacher_id', $existing->teacher_id)
                    ->update(['teacher_id' => $teacher->id]);

                $previous = $existing->teacher->name;
                AuditLog::suspended(fn () => $existing->update(['teacher_id' => $teacher->id]));

                AuditLog::record($user, 'teaching_assignment.reassigned', $existing,
                    ['teacher' => $previous], ['teacher' => $teacher->name, 'draft_mark_sheets_moved' => $moved]);

                return "Reassigned from {$previous} to {$teacher->name}.".($moved ? " {$moved} unsubmitted mark sheet(s) moved with it." : '');
            });
        } catch (UniqueConstraintViolationException) {
            // two people saving the same subject at the same instant: the database key let only one through
            throw ValidationException::withMessages(['teacher_id' => 'Someone else just assigned this subject. Reload and check.']);
        }

        return back()->with('success', $message);
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
