<?php

namespace App\Http\Controllers;

use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\DB;
use App\Models\AuditLog;
use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\Mark;
use App\Models\MarkBatch;
use App\Models\Section;
use App\Models\Subject;
use App\Models\TeachingAssignment;
use App\Models\User;
use App\Notifications\MarkBatchPublished;
use App\Notifications\MarkBatchSubmitted;
use App\Policies\MarkBatchPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;
use Inertia\Response;

class MarkBatchController extends Controller
{
    /**
     * "What do I need to enter?", one card per CLASS instead of one row per class+subject+exam -- with
     * many classes/subjects that flat list became the thing teachers complained was hard to manage.
     * A class is in this list because the person holds a teaching assignment in it, OR because they are
     * its homeroom (class) teacher -- in which case the card covers every subject taught there, not just
     * their own, so a homeroom teacher can cover a colleague who cannot use the system. See forSection()
     * for what opening a card actually shows.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $user->can('enter-marks') || abort(403);

        $assignedSectionIds = $user->teachingAssignments()->pluck('section_id')->unique();
        $homeroomSectionIds = Section::where('class_teacher_id', $user->id)->pluck('id');
        $sectionIds = $assignedSectionIds->merge($homeroomSectionIds)->unique()->values();

        $sections = Section::whereIn('id', $sectionIds)->with(['grade', 'subjects'])->get()->keyBy('id');

        $exams = Exam::where('school_id', $user->school_id)
            ->whereHas('term.academicYear', fn ($q) => $q->where('is_current', true))
            ->get();
        $examCount = max($exams->count(), 1);

        $assignmentsBySection = TeachingAssignment::whereIn('section_id', $sectionIds)->get()->groupBy('section_id');

        // What a homeroom teacher covers is the class's own CURRICULUM (Section::subjects()), not just
        // whatever already happens to have a teacher assigned -- a subject nobody has been given yet
        // still needs marks, and the homeroom teacher is exactly who covers that. Falls back to every
        // subject the school has while that section's curriculum is still unconfigured.
        $allSchoolSubjectIds = $user->school->subjects()->pluck('id');

        $publishedOrFurther = MarkBatch::whereIn('section_id', $sectionIds)
            ->whereIn('status', ['submitted', 'approved', 'published'])
            ->get(['section_id', 'subject_id', 'exam_id'])
            ->map(fn ($b) => "{$b->section_id}-{$b->subject_id}-{$b->exam_id}")
            ->flip();

        $classSizes = Enrollment::active()->whereIn('section_id', $sectionIds)
            ->selectRaw('section_id, COUNT(*) as n')->groupBy('section_id')->pluck('n', 'section_id');

        $classes = $sectionIds->map(function ($sectionId) use ($user, $homeroomSectionIds, $sections, $assignmentsBySection, $exams, $examCount, $publishedOrFurther, $classSizes, $allSchoolSubjectIds) {
            $isHomeroom = $homeroomSectionIds->contains($sectionId);
            $section = $sections->get($sectionId);

            if ($isHomeroom) {
                $subjectIds = $section->subjects->isNotEmpty() ? $section->subjects->pluck('id') : $allSchoolSubjectIds;
            } else {
                $subjectIds = $assignmentsBySection->get($sectionId, collect())->where('teacher_id', $user->id)->pluck('subject_id')->unique();
            }

            $done = 0;
            foreach ($subjectIds as $subjectId) {
                foreach ($exams as $exam) {
                    if ($publishedOrFurther->has("{$sectionId}-{$subjectId}-{$exam->id}")) {
                        $done++;
                    }
                }
            }

            return [
                'section_id' => $sectionId,
                'label' => "{$section->grade->name} {$section->name}",
                'is_homeroom' => $isHomeroom,
                'subjects' => $subjectIds->count(),
                'students' => (int) ($classSizes[$sectionId] ?? 0),
                'done' => $done,
                'total' => $subjectIds->count() * $examCount,
            ];
        })
            ->filter(fn ($c) => $c['subjects'] > 0) // nothing taught here yet -- nothing to enter
            ->sortBy('label')->values();

        return Inertia::render('Marks/Index', ['classes' => $classes]);
    }

    /**
     * One class, opened from its card: every subject the person may enter marks for in it (their own
     * assignment, or -- as the homeroom teacher -- every subject taught here), each as its own card with
     * one row per exam and where its marks stand.
     */
    public function forSection(Request $request, Section $section): Response
    {
        $user = $request->user();
        $user->can('enter-marks') || abort(403);
        $this->assertInScope($user, $section);

        $isHomeroom = MarkBatchPolicy::mayCoverAsHomeroom($user, $section);

        if ($isHomeroom) {
            // Every subject this class actually teaches (or, while that is still unconfigured, every
            // subject the school has) -- the homeroom teacher may cover any of them, whether or not
            // anyone has been assigned to teach it yet.
            $section->loadMissing('subjects');
            $subjects = $section->subjects->isNotEmpty() ? $section->subjects : $user->school->subjects()->get();
        } else {
            // only the subjects THIS person is actually assigned to teach here.
            $subjectIds = TeachingAssignment::where('section_id', $section->id)->where('teacher_id', $user->id)->pluck('subject_id');
            $subjects = Subject::whereIn('id', $subjectIds)->get();
        }

        abort_if($subjects->isEmpty(), 403, 'You are not assigned to teach any subject in this class.');

        $exams = Exam::where('school_id', $user->school_id)
            ->whereHas('term.academicYear', fn ($q) => $q->where('is_current', true))
            ->orderBy('id')->get();

        $existingBatches = MarkBatch::where('section_id', $section->id)->whereIn('subject_id', $subjects->pluck('id'))
            ->withCount(['marks as entered_count' => fn ($q) => $q->whereNotNull('score')])
            ->get()->keyBy(fn ($b) => "{$b->exam_id}-{$b->subject_id}");

        $classSize = Enrollment::active()->where('section_id', $section->id)->count();

        $subjects = $subjects->sortBy('name')->values()->map(fn (Subject $subj) => [
            'subject_id' => $subj->id,
            'subject_name' => $subj->name,
            'exams' => $exams->map(function ($exam) use ($subj, $existingBatches, $classSize) {
                $batch = $existingBatches->get("{$exam->id}-{$subj->id}");

                return [
                    'exam_id' => $exam->id,
                    'exam_name' => $exam->name,
                    'status' => $batch?->status ?? 'not_started',
                    'students' => $classSize,
                    'entered' => (int) ($batch?->entered_count ?? 0),
                ];
            })->values(),
        ]);

        return Inertia::render('Marks/Section', [
            'section' => ['id' => $section->id, 'label' => "{$section->grade->name} {$section->name}"],
            'isHomeroom' => $isHomeroom,
            'subjects' => $subjects,
        ]);
    }

    public function show(Request $request, Exam $exam, Section $section, Subject $subject): Response
    {
        $request->user()->can('enter-marks') || abort(403);
        $user = $request->user();

        $this->assertInScope($user, $section);
        $this->assertAssignedTeacher($user, $section, $subject);

        // the exam must be this school's and belong to the same academic year as the section
        abort_unless($exam->school_id === $user->school_id, 404);
        abort_unless($exam->term()->value('academic_year_id') === $section->academic_year_id, 404);

        $batch = MarkBatch::firstOrCreate(
            ['exam_id' => $exam->id, 'section_id' => $section->id, 'subject_id' => $subject->id],
            ['teacher_id' => $user->id, 'status' => 'draft']
        );

        Gate::forUser($user)->authorize('enterMarks', $batch);

        $enrollments = $section->enrollments()->active()->with('student')->get()->sortBy(fn ($e) => $e->student->first_name)->values();
        $marks = $batch->marks()->get()->keyBy('enrollment_id');

        return Inertia::render('Marks/Enter', [
            'batch' => [
                'id' => $batch->id,
                'status' => $batch->status,
                // ONE maximum for the whole list (the first saved mark's), not whatever the first student happens to show
                'max_score' => (float) ($batch->marks()->orderBy('id')->value('max_score') ?? 100),
            ],
            'missing_mark_policy' => $user->school->missing_mark_policy ?? 'exclude',
            'exam' => ['id' => $exam->id, 'name' => $exam->name],
            'section' => ['id' => $section->id, 'label' => "{$section->grade->name} {$section->name}"],
            'subject' => ['id' => $subject->id, 'name' => $subject->name],
            'backTo' => ['route' => 'marks.section', 'params' => $section->id],
            'roster' => $enrollments->map(fn ($e) => [
                'enrollment_id' => $e->id,
                'student_name' => $e->student->fullName(),
                'student_id_number' => $e->student->student_id_number,
                'score' => $marks->get($e->id)?->score,
                'max_score' => $marks->get($e->id)?->max_score ?? 100,
            ]),
        ]);
    }

    public function store(Request $request, MarkBatch $markBatch): RedirectResponse
    {
        $user = $request->user();

        // The batch id in the URL is only a pointer. Ownership, branch/school scope and the CURRENT
        // teaching assignment are all verified against the database, not against anything the form claims.
        Gate::forUser($user)->authorize('enterMarks', $markBatch);

        $validated = $request->validate([
            'records' => ['required', 'array'],
            'records.*.enrollment_id' => ['required', 'integer'],
            'records.*.score' => ['nullable', 'numeric', 'min:0', 'max:999.99'],
            'records.*.max_score' => ['required', 'numeric', 'min:1', 'max:999.99'],
        ]);

        foreach ($validated['records'] as $i => $record) {
            if ($record['score'] !== null && (float) $record['score'] > (float) $record['max_score']) {
                throw ValidationException::withMessages(["records.{$i}.score" => 'A score cannot be higher than its maximum.']);
            }
        }

        DB::transaction(function () use ($markBatch, $validated, $user) {
            // Lock the batch, then re-check it is STILL a draft: a submit or approval that landed a moment
            // ago must win over this save.
            $locked = MarkBatch::whereKey($markBatch->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'draft', 422, 'Marks can only be edited while the batch is in draft.');
            Gate::forUser($user)->authorize('enterMarks', $locked);

            // A homeroom teacher covering a colleague's subject is not its regular teacher of record --
            // the moment they actually save is when they become the batch's acting teacher, same as a
            // reassignment moving an unsubmitted sheet to whoever teaches the subject now.
            if ($locked->teacher_id !== $user->id) {
                $previousTeacherId = $locked->teacher_id;
                $locked->update(['teacher_id' => $user->id]);
                AuditLog::record($user, 'mark_batch.covered', $locked, ['teacher_id' => $previousTeacherId], ['teacher_id' => $user->id]);
            }

            $validEnrollmentIds = $locked->section->enrollments()->active()->pluck('id');

            foreach ($validated['records'] as $record) {
                abort_unless($validEnrollmentIds->contains($record['enrollment_id']), 422);

                $existing = Mark::where('mark_batch_id', $locked->id)
                    ->where('enrollment_id', $record['enrollment_id'])
                    ->first();

                $oldScore = $existing?->score;
                $newScore = $record['score'];

                $mark = Mark::updateOrCreate(
                    ['mark_batch_id' => $locked->id, 'enrollment_id' => $record['enrollment_id']],
                    ['score' => $newScore, 'max_score' => $record['max_score']]
                );

                if ((float) $oldScore !== (float) $newScore) {
                    AuditLog::record($user, 'mark.updated', $mark, ['score' => $oldScore], ['score' => $newScore]);
                }
            }
        });

        return back()->with('success', 'Marks saved.');
    }

    public function submit(Request $request, MarkBatch $markBatch): RedirectResponse
    {
        Gate::forUser($request->user())->authorize('enterMarks', $markBatch);

        DB::transaction(function () use ($request, $markBatch) {
            $locked = MarkBatch::whereKey($markBatch->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'draft', 422, 'Only a draft can be submitted.');

            $locked->update(['status' => 'submitted', 'submitted_at' => now()]);
            AuditLog::record($request->user(), 'mark_batch.submitted', $locked, ['status' => 'draft'], ['status' => 'submitted']);
        });

        $this->notifyApprovers($markBatch->refresh());

        return back()->with('success', 'Submitted for approval.');
    }

    public function approvalsIndex(Request $request): Response
    {
        $request->user()->can('approve-results') || $request->user()->can('publish-results') || abort(403);
        $user = $request->user();

        $query = MarkBatch::whereHas('section', function ($q) use ($user) {
            $q->whereIn('branch_id', $user->school->branches()->pluck('id'));
            if (! $user->isOrgWide()) {
                $q->where('branch_id', $user->branch_id);
            }
        })->with(['exam', 'section.grade', 'subject', 'teacher']);

        $totals = Exam::where('school_id', $user->school_id)
            ->selectRaw('term_id, SUM(weight_percent) as total')->groupBy('term_id')->pluck('total', 'term_id');

        $batches = $query->orderByDesc('id')->get()->map(fn (MarkBatch $b) => [
            'id' => $b->id,
            'weights_total' => round((float) ($totals[$b->exam->term_id] ?? 0), 2),
            'weights_ok' => Exam::isCompleteTotal((float) ($totals[$b->exam->term_id] ?? 0)),
            'exam' => $b->exam->name,
            'section' => "{$b->section->grade->name} {$b->section->name}",
            'subject' => $b->subject->name,
            'teacher' => $b->teacher->name,
            'status' => $b->status,
        ]);

        return Inertia::render('Marks/Approvals', [
            'batches' => $batches,
            'canApprove' => $user->can('approve-results'),
            'canPublish' => $user->can('publish-results'),
        ]);
    }

    public function approve(Request $request, MarkBatch $markBatch): RedirectResponse
    {
        $request->user()->can('approve-results') || abort(403);
        $this->assertBranchInScope($request->user(), $markBatch);
        abort_unless($markBatch->status === 'submitted', 422);

        $markBatch->update(['status' => 'approved', 'approved_at' => now(), 'approved_by' => $request->user()->id]);
        AuditLog::record($request->user(), 'mark_batch.approved', $markBatch, ['status' => 'submitted'], ['status' => 'approved']);

        return back()->with('success', 'Results approved.');
    }

    public function sendBack(Request $request, MarkBatch $markBatch): RedirectResponse
    {
        $request->user()->can('approve-results') || abort(403);
        $this->assertBranchInScope($request->user(), $markBatch);
        abort_unless(in_array($markBatch->status, ['submitted', 'approved'], true), 422);

        $from = $markBatch->status;
        // it returns to whoever holds the teaching assignment now (it may have been reassigned since)
        $markBatch->update(['status' => 'draft', 'submitted_at' => null, 'approved_at' => null, 'approved_by' => null, 'teacher_id' => $markBatch->currentTeacherId() ?? $markBatch->teacher_id]);
        AuditLog::record($request->user(), 'mark_batch.sent_back', $markBatch, ['status' => $from], ['status' => 'draft']);

        return back()->with('success', 'Sent back to the teacher for changes.');
    }

    public function publish(Request $request, MarkBatch $markBatch): RedirectResponse
    {
        $request->user()->can('publish-results') || abort(403);
        $this->assertBranchInScope($request->user(), $markBatch);
        abort_unless($markBatch->status === 'approved', 422);

        $termId = $markBatch->exam->term_id;
        $total = Exam::termWeightTotal($termId);
        if (! Exam::isCompleteTotal($total)) {
            return back()->withErrors(['publish' => "Can't publish: the exam weights for {$markBatch->exam->term->name} total {$total}%, not 100%. Fix them under Exams first, so report cards are calculated from a complete grading scheme."]);
        }

        $publication = DB::transaction(function () use ($request, $markBatch) {
            $locked = MarkBatch::whereKey($markBatch->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'approved', 422);

            $publication = $locked->publishBy($request->user());
            AuditLog::record($request->user(), 'mark_batch.published', $locked, ['status' => 'approved'], ['status' => 'published', 'version' => $publication->version, 'marks' => count($publication->marks_snapshot)]);

            return $publication;
        });

        $this->notifyGuardians($markBatch->refresh());

        return back()->with('success', 'Results published — visible to parents and students now.');
    }

    /**
     * The ONLY way published results become editable again. Needs publish-results, a reason (at least a
     * few words, stored and audited), and leaves the old published version on record with who reopened it,
     * when and why. Afterwards the marks are an ordinary draft; publishing again creates a new version.
     */
    public function reopen(Request $request, MarkBatch $markBatch): RedirectResponse
    {
        $user = $request->user();
        $user->can('publish-results') || abort(403);
        $this->assertBranchInScope($user, $markBatch);

        $validated = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        DB::transaction(function () use ($user, $markBatch, $validated) {
            $locked = MarkBatch::whereKey($markBatch->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'published', 422, 'Only published results can be reopened.');

            $live = $locked->livePublication();
            $before = [
                'status' => 'published',
                'version' => $live?->version,
                'published_at' => $locked->published_at?->toDateTimeString(),
                'published_by' => $live?->publishedBy?->name,
                'approved_by' => $locked->approvedBy?->name,
                'marks' => count($live?->marks_snapshot ?? []),
            ];

            $locked->reopenPublished($user, $validated['reason']);

            AuditLog::record($user, 'mark_batch.reopened', $locked, $before, ['status' => 'draft'], $validated['reason']);
        });

        return back()->with('success', 'Reopened for correction. The published version stays on record and this is logged in the audit trail.');
    }

    /** Whoever holds approve-results in the batch's own branch (plus org-wide holders) -- the same
     * "scope then filter by actual permission" shape ComplaintController::recipientsFor() uses. */
    private function notifyApprovers(MarkBatch $batch): void
    {
        $batch->loadMissing('section', 'subject', 'exam', 'teacher');
        $branchId = $batch->section->branch_id;

        $recipients = User::where('school_id', $batch->exam->school_id)
            ->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branchId))
            ->get()
            ->filter(fn ($u) => $u->can('approve-results'));

        Notification::send($recipients, new MarkBatchSubmitted($batch));
    }

    /** One instance per enrolled student, to that student's own guardians -- the same shape
     * Assignment::notifyGuardians() already uses, so each guardian's own link lands on the right child
     * even though the batch itself covers a whole section. */
    private function notifyGuardians(MarkBatch $batch): void
    {
        $batch->loadMissing('section');
        $enrollments = $batch->section->enrollments()->active()->with('student.guardians')->get();

        foreach ($enrollments as $enrollment) {
            $guardians = $enrollment->student->guardians;
            if ($guardians->isEmpty()) {
                continue;
            }

            Notification::send($guardians, new MarkBatchPublished($batch, $enrollment->student));
        }
    }

    /** The real assignment, OR covering as the section's own homeroom teacher for a colleague who
     *  cannot use the system -- see MarkBatchPolicy::mayCoverAsHomeroom() for the exact same rule
     *  applied again (under lock) by the Gate once a batch exists. */
    private function assertAssignedTeacher($user, Section $section, Subject $subject): void
    {
        $assigned = $user->teachingAssignments()->where('section_id', $section->id)->where('subject_id', $subject->id)->exists();

        abort_unless(
            $assigned || MarkBatchPolicy::mayCoverAsHomeroom($user, $section),
            403,
            'You are not assigned to teach this subject for this section.'
        );
    }

    private function assertInScope($user, Section $section): void
    {
        abort_unless($section->branch->school_id === $user->school_id, 403);
        abort_unless($user->isOrgWide() || $user->branch_id === $section->branch_id, 403);
    }

    private function assertBranchInScope($user, MarkBatch $markBatch): void
    {
        $branch = $user->school->branches()->whereKey($markBatch->section->branch_id)->first();
        abort_unless($branch, 403);

        if (! $user->isOrgWide()) {
            abort_unless($user->branch_id === $markBatch->section->branch_id, 403);
        }
    }

    /**
     * The approver's own look at a batch's actual marks before they approve/reject it -- a branch_admin
     * holding approve-results/publish-results but NOT enter-marks could previously only see the batch's
     * metadata (section/subject/teacher/status) on the Approvals queue, never the scores themselves,
     * and had no route that would let them in to look (MarkBatchController::show() is hard-gated to
     * enter-marks). This reuses the exact same read-only rendering Marks/Enter.jsx already shows a
     * TEACHER once their own batch leaves draft (status badge, "what happens next", a plain list of
     * name + score) -- never the editable form, regardless of the batch's actual status, and never a
     * route that can save anything: no store()/submit() equivalent exists here.
     */
    public function viewForApproval(Request $request, MarkBatch $markBatch): Response
    {
        $user = $request->user();
        ($user->can('approve-results') || $user->can('publish-results')) || abort(403);
        $this->assertBranchInScope($user, $markBatch);

        $markBatch->load(['exam', 'section.grade', 'subject']);
        $enrollments = $markBatch->section->enrollments()->active()->with('student')->get()->sortBy(fn ($e) => $e->student->first_name)->values();
        $marks = $markBatch->marks()->get()->keyBy('enrollment_id');

        return Inertia::render('Marks/Enter', [
            'readOnly' => true,
            'backTo' => ['route' => 'mark-batches.approvals'],
            'batch' => [
                'id' => $markBatch->id,
                'status' => $markBatch->status,
                'max_score' => (float) ($markBatch->marks()->orderBy('id')->value('max_score') ?? 100),
            ],
            'missing_mark_policy' => $user->school->missing_mark_policy ?? 'exclude',
            'exam' => ['id' => $markBatch->exam->id, 'name' => $markBatch->exam->name],
            'section' => ['id' => $markBatch->section->id, 'label' => "{$markBatch->section->grade->name} {$markBatch->section->name}"],
            'subject' => ['id' => $markBatch->subject->id, 'name' => $markBatch->subject->name],
            'roster' => $enrollments->map(fn ($e) => [
                'enrollment_id' => $e->id,
                'student_name' => $e->student->fullName(),
                'student_id_number' => $e->student->student_id_number,
                'score' => $marks->get($e->id)?->score,
                'max_score' => $marks->get($e->id)?->max_score ?? 100,
            ]),
        ]);
    }
}
