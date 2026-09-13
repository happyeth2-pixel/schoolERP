<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Exam;
use App\Models\Mark;
use App\Models\MarkBatch;
use App\Models\Section;
use App\Models\Subject;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MarkBatchController extends Controller
{
    /**
     * Teacher's own "what do I need to enter" list: every teaching
     * assignment they hold, crossed with every exam in the current
     * academic year, showing the batch status for that combination
     * (or "Not started" if no batch exists yet).
     */
    public function index(Request $request): Response
    {
        $request->user()->can('enter-marks') || abort(403);
        $user = $request->user();

        $assignments = $user->teachingAssignments()->with(['section.grade', 'subject'])->get();
        $exams = Exam::where('school_id', $user->school_id)
            ->whereHas('term.academicYear', fn ($q) => $q->where('is_current', true))
            ->with('term')
            ->orderBy('id')
            ->get();

        $existingBatches = MarkBatch::where('teacher_id', $user->id)->get()->keyBy(fn ($b) => "{$b->exam_id}-{$b->section_id}-{$b->subject_id}");

        $rows = [];
        foreach ($assignments as $a) {
            foreach ($exams as $exam) {
                $key = "{$exam->id}-{$a->section_id}-{$a->subject_id}";
                $batch = $existingBatches->get($key);
                $rows[] = [
                    'exam_id' => $exam->id,
                    'exam_name' => $exam->name,
                    'section_id' => $a->section_id,
                    'section_label' => "{$a->section->grade->name} {$a->section->name}",
                    'subject_id' => $a->subject_id,
                    'subject_name' => $a->subject->name,
                    'status' => $batch?->status ?? 'not_started',
                ];
            }
        }

        return Inertia::render('Marks/Index', ['rows' => $rows]);
    }

    public function show(Request $request, Exam $exam, Section $section, Subject $subject): Response
    {
        $request->user()->can('enter-marks') || abort(403);
        $user = $request->user();

        $this->assertAssignedTeacher($user, $section, $subject);

        $batch = MarkBatch::firstOrCreate(
            ['exam_id' => $exam->id, 'section_id' => $section->id, 'subject_id' => $subject->id],
            ['teacher_id' => $user->id, 'status' => 'draft']
        );

        abort_unless($batch->teacher_id === $user->id, 403, 'Another teacher owns this mark batch.');

        $enrollments = $section->enrollments()->where('status', 'active')->with('student')->get()->sortBy(fn ($e) => $e->student->first_name)->values();
        $marks = $batch->marks()->get()->keyBy('enrollment_id');

        return Inertia::render('Marks/Enter', [
            'batch' => [
                'id' => $batch->id,
                'status' => $batch->status,
            ],
            'exam' => ['id' => $exam->id, 'name' => $exam->name],
            'section' => ['id' => $section->id, 'label' => "{$section->grade->name} {$section->name}"],
            'subject' => ['id' => $subject->id, 'name' => $subject->name],
            'roster' => $enrollments->map(fn ($e) => [
                'enrollment_id' => $e->id,
                'student_name' => $e->student->fullName(),
                'score' => $marks->get($e->id)?->score,
                'max_score' => $marks->get($e->id)?->max_score ?? 100,
            ]),
        ]);
    }

    public function store(Request $request, MarkBatch $markBatch): RedirectResponse
    {
        $request->user()->can('enter-marks') || abort(403);
        $user = $request->user();
        abort_unless($markBatch->teacher_id === $user->id, 403);
        abort_unless($markBatch->status === 'draft', 422, 'Marks can only be edited while the batch is in draft.');

        $validated = $request->validate([
            'records' => ['required', 'array'],
            'records.*.enrollment_id' => ['required', 'integer'],
            'records.*.score' => ['nullable', 'numeric', 'min:0'],
            'records.*.max_score' => ['required', 'numeric', 'min:1'],
        ]);

        $validEnrollmentIds = $markBatch->section->enrollments()->where('status', 'active')->pluck('id');

        foreach ($validated['records'] as $record) {
            abort_unless($validEnrollmentIds->contains($record['enrollment_id']), 422);

            $existing = Mark::where('mark_batch_id', $markBatch->id)
                ->where('enrollment_id', $record['enrollment_id'])
                ->first();

            $oldScore = $existing?->score;
            $newScore = $record['score'];

            $mark = Mark::updateOrCreate(
                ['mark_batch_id' => $markBatch->id, 'enrollment_id' => $record['enrollment_id']],
                ['score' => $newScore, 'max_score' => $record['max_score']]
            );

            if ((float) $oldScore !== (float) $newScore) {
                AuditLog::record(
                    $user,
                    'mark.updated',
                    $mark,
                    ['score' => $oldScore],
                    ['score' => $newScore],
                );
            }
        }

        return back()->with('success', 'Marks saved.');
    }

    public function submit(Request $request, MarkBatch $markBatch): RedirectResponse
    {
        $request->user()->can('enter-marks') || abort(403);
        abort_unless($markBatch->teacher_id === $request->user()->id, 403);
        abort_unless($markBatch->status === 'draft', 422);

        $markBatch->update(['status' => 'submitted', 'submitted_at' => now()]);
        AuditLog::record($request->user(), 'mark_batch.submitted', $markBatch, ['status' => 'draft'], ['status' => 'submitted']);

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

        $batches = $query->orderByDesc('id')->get()->map(fn (MarkBatch $b) => [
            'id' => $b->id,
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
        $markBatch->update(['status' => 'draft', 'submitted_at' => null, 'approved_at' => null, 'approved_by' => null]);
        AuditLog::record($request->user(), 'mark_batch.sent_back', $markBatch, ['status' => $from], ['status' => 'draft']);

        return back()->with('success', 'Sent back to the teacher for changes.');
    }

    public function publish(Request $request, MarkBatch $markBatch): RedirectResponse
    {
        $request->user()->can('publish-results') || abort(403);
        $this->assertBranchInScope($request->user(), $markBatch);
        abort_unless($markBatch->status === 'approved', 422);

        $markBatch->update(['status' => 'published', 'published_at' => now()]);
        AuditLog::record($request->user(), 'mark_batch.published', $markBatch, ['status' => 'approved'], ['status' => 'published']);

        return back()->with('success', 'Results published — visible to parents and students now.');
    }

    public function reopen(Request $request, MarkBatch $markBatch): RedirectResponse
    {
        $request->user()->can('publish-results') || abort(403);
        $this->assertBranchInScope($request->user(), $markBatch);
        abort_unless($markBatch->status === 'published', 422);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $markBatch->update(['status' => 'draft', 'published_at' => null, 'approved_at' => null, 'approved_by' => null]);
        AuditLog::record(
            $request->user(),
            'mark_batch.reopened',
            $markBatch,
            ['status' => 'published'],
            ['status' => 'draft'],
            $validated['reason'],
        );

        return back()->with('success', 'Reopened for correction. This is logged in the audit trail.');
    }

    private function assertAssignedTeacher($user, Section $section, Subject $subject): void
    {
        abort_unless(
            $user->teachingAssignments()->where('section_id', $section->id)->where('subject_id', $subject->id)->exists(),
            403,
            'You are not assigned to teach this subject for this section.'
        );
    }

    private function assertBranchInScope($user, MarkBatch $markBatch): void
    {
        $branch = $user->school->branches()->whereKey($markBatch->section->branch_id)->first();
        abort_unless($branch, 403);

        if (! $user->isOrgWide()) {
            abort_unless($user->branch_id === $markBatch->section->branch_id, 403);
        }
    }
}
