<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Models\Section;
use App\Models\TeachingAssignment;
use App\Models\User;
use App\Rules\SafeUploadRule;
use App\Support\SafeUpload;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Academic expansion, Phase 8: "a simple foundation for teacher assignments" (the brief's own words) --
 * subject/class/teacher/title/description/due date/attachment/status, never a submission/grading system.
 * `manage-assignments` already existed, reserved and unused, since the original base spec -- no seeder
 * change was needed for this phase at all. `teacher_id` is always derived from the CURRENT
 * `teaching_assignments` row for the section+subject, and a teacher is re-checked against that row every
 * time (never a stored id trusted forever), the same live-reauthorization principle `MarkBatchPolicy`
 * already established. A branch admin may manage any section+subject in their branch without holding the
 * teaching assignment themselves -- the same "can cover" shape `mark-attendance` already has.
 */
class AssignmentController extends Controller
{
    private function sectionsInScope(User $user)
    {
        return Section::whereHas('branch', fn ($q) => $q->where('school_id', $user->school_id))
            ->when(! $user->isOrgWide(), fn ($q) => $q->where('branch_id', $user->branch_id));
    }

    private function assertInScope(User $user, Section $section): void
    {
        abort_unless($section->branch->school_id === $user->school_id, 403);
        abort_unless($user->isOrgWide() || $section->branch_id === $user->branch_id, 403);
    }

    private function assertCanManage(User $user, Section $section, int $subjectId): void
    {
        abort_unless($user->can('manage-assignments'), 403);
        $this->assertInScope($user, $section);

        if ($user->hasRole('teacher')) {
            abort_unless(
                $user->teachingAssignments()->where('section_id', $section->id)->where('subject_id', $subjectId)->exists(),
                403,
                'You are not currently assigned to teach this subject for this class.'
            );
        }
    }

    private function canViewAsStaff(User $user, Assignment $assignment): bool
    {
        if (! $user->can('manage-assignments') && ! $user->hasRole('auditor')) {
            return false;
        }
        if ($assignment->section->branch->school_id !== $user->school_id) {
            return false;
        }
        if (! $user->isOrgWide() && $assignment->section->branch_id !== $user->branch_id) {
            return false;
        }
        if ($user->hasRole('teacher')) {
            return $user->teachingAssignments()->where('section_id', $assignment->section_id)->where('subject_id', $assignment->subject_id)->exists();
        }

        return true;
    }

    private function canViewAsGuardian(User $user, Assignment $assignment): bool
    {
        return $assignment->section->enrollments()->active()
            ->whereHas('student.guardians', fn ($q) => $q->where('users.id', $user->id))
            ->exists();
    }

    /** Teacher's own assignments (across every class/subject they currently teach), or the whole
     * branch's for an admin. Also returns the valid section+subject combinations a new one may be
     * created against, so the create form can only ever pick a real, current teaching assignment. */
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->can('manage-assignments'), 403);

        $teachingAssignments = $user->hasRole('teacher')
            ? $user->teachingAssignments()->with(['section.grade', 'subject'])->get()
            : TeachingAssignment::whereIn('section_id', $this->sectionsInScope($user)->pluck('id'))
                ->with(['section.grade', 'subject', 'teacher'])->get();

        $assignments = Assignment::whereIn('section_id', $teachingAssignments->pluck('section_id')->unique())
            ->when($user->hasRole('teacher'), fn ($q) => $q->where('teacher_id', $user->id))
            ->with(['section.grade', 'subject', 'teacher'])
            ->orderByDesc('due_date')
            ->get()
            ->map(fn (Assignment $a) => $this->present($a));

        return Inertia::render('Assignments/Index', [
            'assignments' => $assignments,
            'teachingAssignments' => $teachingAssignments->map(fn (TeachingAssignment $ta) => [
                'id' => $ta->id,
                'section_id' => $ta->section_id,
                'subject_id' => $ta->subject_id,
                'label' => "{$ta->section->grade->name} {$ta->section->name} — {$ta->subject->name}",
            ])->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $validated = $this->validated($request, isCreate: true);

        $section = Section::findOrFail($validated['section_id']);
        $this->assertCanManage($user, $section, $validated['subject_id']);

        $teacherId = TeachingAssignment::where('section_id', $section->id)->where('subject_id', $validated['subject_id'])->value('teacher_id');
        abort_unless($teacherId, 422, 'No teacher is currently assigned to teach this subject for this class.');

        $assignment = Assignment::create([
            'section_id' => $section->id,
            'subject_id' => $validated['subject_id'],
            'teacher_id' => $teacherId,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'due_date' => $validated['due_date'],
            'status' => 'draft',
        ]);

        if ($request->hasFile('attachment')) {
            $this->storeAttachment($assignment, $request->file('attachment'));
        }

        if ($validated['status'] === 'published') {
            $assignment->publish();
        }

        return back()->with('success', 'Assignment saved.');
    }

    public function update(Request $request, Assignment $assignment): RedirectResponse
    {
        $user = $request->user();
        $this->assertCanManage($user, $assignment->section, $assignment->subject_id);

        $validated = $this->validated($request, isCreate: false);

        // the class/subject this assignment is FOR is fixed at creation -- editing only changes content
        $assignment->update([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'due_date' => $validated['due_date'],
        ]);

        if ($request->hasFile('attachment')) {
            $this->storeAttachment($assignment, $request->file('attachment'));
        }

        if ($validated['status'] === 'published') {
            $assignment->publish();
        }

        return back()->with('success', 'Assignment updated.');
    }

    public function destroy(Request $request, Assignment $assignment): RedirectResponse
    {
        $user = $request->user();
        $this->assertCanManage($user, $assignment->section, $assignment->subject_id);

        if ($assignment->attachment_path) {
            Storage::disk('local')->delete($assignment->attachment_path);
        }
        $assignment->delete();

        return back()->with('success', 'Assignment removed.');
    }

    public function document(Request $request, Assignment $assignment): SymfonyResponse
    {
        $user = $request->user();
        abort_unless($this->canViewAsStaff($user, $assignment) || $this->canViewAsGuardian($user, $assignment), 403);
        abort_unless($assignment->attachment_path && Storage::disk('local')->exists($assignment->attachment_path), 404);

        [$ext, $mime] = SafeUpload::servingType($assignment->attachment_path);

        return Storage::disk('local')->response($assignment->attachment_path, "assignment-{$assignment->id}.{$ext}", [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "sandbox; default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'",
            'Cache-Control' => 'private, no-store',
        ], 'inline');
    }

    private function present(Assignment $a): array
    {
        return [
            'id' => $a->id,
            'section_id' => $a->section_id,
            'section_label' => $a->section->auditLabel(),
            'subject_id' => $a->subject_id,
            'subject_name' => $a->subject->name,
            'teacher_name' => $a->teacher->name,
            'title' => $a->title,
            'description' => $a->description,
            'due_date' => $a->due_date->toDateString(),
            'status' => $a->status,
            'has_attachment' => $a->attachment_path !== null,
        ];
    }

    private function validated(Request $request, bool $isCreate): array
    {
        $rules = [
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'due_date' => $isCreate ? ['required', 'date', 'after_or_equal:today'] : ['required', 'date'],
            'status' => ['required', Rule::in(Assignment::STATUSES)],
            'attachment' => ['nullable', 'file', 'max:5120', new SafeUploadRule(['jpg', 'png', 'pdf'])],
        ];

        if ($isCreate) {
            $rules['section_id'] = ['required', 'integer', 'exists:sections,id'];
            $rules['subject_id'] = ['required', 'integer', 'exists:subjects,id'];
        }

        return $request->validate($rules);
    }

    private function storeAttachment(Assignment $assignment, $file): void
    {
        $old = $assignment->attachment_path;
        $assignment->update([
            'attachment_path' => SafeUpload::storePrivate($file, 'assignments', ['jpg', 'png', 'pdf']),
            'attachment_original_name' => $file->getClientOriginalName(),
        ]);
        if ($old) {
            Storage::disk('local')->delete($old);
        }
    }
}
