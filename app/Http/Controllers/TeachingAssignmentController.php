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
            ->with(['grade', 'subjects', 'branch', 'classTeacher', 'teachingAssignments.subject', 'teachingAssignments.teacher']);

        if (! $user->isOrgWide()) {
            $sectionsQuery->where('branch_id', $user->branch_id);
        }

        // Every subject the school has, for whenever a section's own curriculum is still "not configured
        // yet" (see Section::subjects()) -- a brand new school, or a class that hasn't been curated down
        // yet, keeps seeing every subject exactly as before.
        $allSubjects = $user->school->subjects()->orderBy('name')->get(['id', 'name']);

        $teachersQuery = User::where('school_id', $user->school_id)->where('status', 'active');
        if (! $user->isOrgWide()) {
            $teachersQuery->where('branch_id', $user->branch_id);
        }
        // Anyone who actually holds the teacher role, PLUS any other staff member (a principal,
        // a branch admin) whose account can enter marks -- so a teacher who cannot use the system can
        // be covered by someone senior enough assigning the subject to themselves instead, the same way
        // any other teaching assignment is given. Nothing here grants `enter-marks` by itself: it only
        // widens who can be PICKED here to those who already hold it.
        $teachers = $teachersQuery->orderBy('name')->get(['id', 'name', 'branch_id'])
            ->filter(fn (User $u) => $u->hasRole('teacher') || $u->can('enter-marks'))
            ->values();

        return Inertia::render('TeachingAssignments/Index', [
            'sections' => $sectionsQuery->orderBy('grade_id')->get()->map(fn (Section $s) => [
                'id' => $s->id,
                'label' => "{$s->grade->name} {$s->name} ({$s->branch->name})",
                'grade' => $s->grade->name,
                'name' => $s->name,
                'branch_id' => $s->branch_id,
                'branch' => $s->branch->name,
                'class_teacher' => $s->classTeacher?->only(['id', 'name']),
                // 1A and 1B are not the same curriculum: offer only what THIS section actually teaches,
                // falling back to every subject while this one's own list is still empty.
                'subjects' => ($s->subjects->isNotEmpty() ? $s->subjects : $allSubjects)
                    ->map(fn ($subj) => ['id' => $subj->id, 'name' => $subj->name])->sortBy('name')->values(),
                'assignments' => $s->teachingAssignments->sortBy(fn ($a) => $a->subject->name)->values()->map(fn (TeachingAssignment $a) => [
                    'id' => $a->id,
                    'subject_id' => $a->subject_id,
                    'subject' => $a->subject->name,
                    'teacher' => $a->teacher->name,
                ]),
            ]),
            'teachers' => $teachers,
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
            ->firstOrFail();
        abort_unless($teacher->hasRole('teacher') || $teacher->can('enter-marks'), 422, 'This person cannot be given a subject to teach.');
        // An org-wide owner has no single home branch to match -- that is exactly what "org-wide" means,
        // not a reason to refuse giving them a subject in any one branch's section.
        abort_unless($teacher->isOrgWide() || $teacher->branch_id === $section->branch_id, 422, 'Teacher must belong to the same branch as the section.');

        abort_unless(
            $user->school->subjects()->whereKey($validated['subject_id'])->exists(),
            422
        );

        // The browser's own picker already only offers this section's own subjects (or, while its
        // curriculum is still unconfigured, every subject) -- re-checked here so a crafted request can't
        // give a class a subject outside its own curriculum once one actually IS configured. 1A and 1B
        // are not guaranteed to share one, so this is checked per section, not per grade.
        $sectionSubjectIds = $section->subjects()->pluck('subjects.id');
        abort_unless($sectionSubjectIds->isEmpty() || $sectionSubjectIds->contains($validated['subject_id']), 422, 'This subject is not taught in this class.');

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
