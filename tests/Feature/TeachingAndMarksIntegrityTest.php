<?php

namespace Tests\Feature;

use App\Exceptions\LockedRecordException;
use App\Models\AuditLog;
use App\Models\Exam;
use App\Models\Mark;
use App\Models\MarkBatch;
use App\Models\MarkBatchPublication;
use App\Models\Subject;
use App\Models\TeachingAssignment;
use App\Models\Term;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

/** Items 38-40: one teacher per section+subject, mark ownership checked in the backend, published marks locked. */
class TeachingAndMarksIntegrityTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    private Subject $math;
    private Exam $exam;
    private User $teacherA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchool();
        $this->math = Subject::create(['school_id' => $this->school->id, 'name' => 'Math', 'code' => 'M']);
        $term = Term::create(['academic_year_id' => $this->year->id, 'name' => 'T1', 'start_date' => '2026-09-01', 'end_date' => '2026-12-20']);
        $this->exam = Exam::create(['school_id' => $this->school->id, 'term_id' => $term->id, 'name' => 'Final', 'weight_percent' => 100]);
        $this->teacherA = $this->makeUser('teacher', $this->branchA->id);
        TeachingAssignment::create(['section_id' => $this->sectionA->id, 'subject_id' => $this->math->id, 'teacher_id' => $this->teacherA->id]);
    }

    private function batch(?User $teacher = null, string $status = 'draft'): MarkBatch
    {
        return MarkBatch::create(['exam_id' => $this->exam->id, 'section_id' => $this->sectionA->id, 'subject_id' => $this->math->id, 'teacher_id' => ($teacher ?? $this->teacherA)->id, 'status' => $status]);
    }

    private function save(User $u, MarkBatch $b, float $score = 50)
    {
        $e = $this->studentA->currentEnrollment;

        return $this->actingAs($u)->post("/mark-batches/{$b->id}", ['records' => [['enrollment_id' => $e->id, 'score' => $score, 'max_score' => 100]]]);
    }

    private function toPublished(MarkBatch $b, User $approver): void
    {
        $this->actingAs($this->teacherA)->post("/mark-batches/{$b->id}/submit")->assertSessionHasNoErrors();
        $this->actingAs($approver)->post("/mark-batches/{$b->id}/approve")->assertSessionHasNoErrors();
        $this->actingAs($approver)->post("/mark-batches/{$b->id}/publish")->assertSessionHasNoErrors();
    }

    // ------------------------------------------------------------------ 38

    public function test_the_database_allows_one_teacher_per_section_and_subject(): void
    {
        $other = $this->makeUser('teacher', $this->branchA->id);

        // the same teacher twice, and a second teacher: both refused by the unique key
        foreach ([$this->teacherA, $other] as $t) {
            try {
                TeachingAssignment::create(['section_id' => $this->sectionA->id, 'subject_id' => $this->math->id, 'teacher_id' => $t->id]);
                $this->fail('duplicate assignment was stored');
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame(1, TeachingAssignment::count());

        // a different subject, or the same subject in another section, is a different assignment
        $sci = Subject::create(['school_id' => $this->school->id, 'name' => 'Science', 'code' => 'S']);
        TeachingAssignment::create(['section_id' => $this->sectionA->id, 'subject_id' => $sci->id, 'teacher_id' => $this->teacherA->id]);
        $sectionA2 = \App\Models\Section::create(['branch_id' => $this->branchA->id, 'grade_id' => $this->grade->id, 'academic_year_id' => $this->year->id, 'name' => 'A2']);
        TeachingAssignment::create(['section_id' => $sectionA2->id, 'subject_id' => $this->math->id, 'teacher_id' => $this->teacherA->id]);
        $this->assertSame(3, TeachingAssignment::count());
    }

    public function test_assigning_the_same_teacher_again_is_refused_and_a_different_one_needs_confirmation(): void
    {
        $admin = $this->makeUser('branch_admin', $this->branchA->id);
        $other = $this->makeUser('teacher', $this->branchA->id);
        $post = fn ($teacher, array $extra = []) => $this->actingAs($admin)->post('/teaching-assignments', ['section_id' => $this->sectionA->id, 'subject_id' => $this->math->id, 'teacher_id' => $teacher->id] + $extra);

        $post($this->teacherA)->assertSessionHasErrors('teacher_id');
        $post($other)->assertSessionHasErrors('teacher_id');
        $this->assertSame($this->teacherA->id, TeachingAssignment::first()->teacher_id, 'nothing was silently replaced');

        $post($other, ['replace' => true])->assertSessionHasNoErrors();
        $this->assertSame($other->id, TeachingAssignment::first()->teacher_id);
        $this->assertSame(1, TeachingAssignment::count());
        $this->assertNotNull(AuditLog::where('action', 'teaching_assignment.reassigned')->first());
        $this->assertSame(0, AuditLog::where('action', 'teaching_assignment.updated')->count(), 'one entry, not two');
    }

    public function test_reassigning_moves_unsubmitted_marks_but_not_submitted_or_published_ones(): void
    {
        $admin = $this->makeUser('branch_admin', $this->branchA->id);
        $other = $this->makeUser('teacher', $this->branchA->id);
        $draft = $this->batch();
        $exam2 = Exam::create(['school_id' => $this->school->id, 'term_id' => $this->exam->term_id, 'name' => 'Quiz', 'weight_percent' => 0.01]);
        $submitted = MarkBatch::create(['exam_id' => $exam2->id, 'section_id' => $this->sectionA->id, 'subject_id' => $this->math->id, 'teacher_id' => $this->teacherA->id, 'status' => 'draft']);
        $submitted->update(['status' => 'submitted']);

        $this->actingAs($admin)->post('/teaching-assignments', ['section_id' => $this->sectionA->id, 'subject_id' => $this->math->id, 'teacher_id' => $other->id, 'replace' => true])->assertSessionHasNoErrors();

        $this->assertSame($other->id, $draft->fresh()->teacher_id, 'the draft follows the subject');
        $this->assertSame($this->teacherA->id, $submitted->fresh()->teacher_id, 'a submitted sheet stays attributed to who entered it');

        // and the former teacher can no longer edit it, even though the batch row once had their id
        $this->save($this->teacherA, $draft)->assertForbidden();
        $this->save($other, $draft)->assertSessionHasNoErrors();

        // a sheet sent back goes to whoever teaches it NOW
        $approver = $this->makeUser('branch_admin', $this->branchA->id);
        $this->actingAs($approver)->post("/mark-batches/{$submitted->id}/send-back")->assertSessionHasNoErrors();
        $this->assertSame($other->id, $submitted->fresh()->teacher_id);
    }

    public function test_teachers_outside_the_branch_or_inactive_cannot_be_assigned(): void
    {
        $admin = $this->makeUser('branch_admin', $this->branchA->id);
        $sci = Subject::create(['school_id' => $this->school->id, 'name' => 'Science', 'code' => 'S']);
        $east = $this->makeUser('teacher', $this->branchB->id);
        $gone = $this->makeUser('teacher', $this->branchA->id);
        $gone->update(['status' => 'inactive']);

        $this->actingAs($admin)->post('/teaching-assignments', ['section_id' => $this->sectionA->id, 'subject_id' => $sci->id, 'teacher_id' => $east->id])->assertStatus(422);
        $this->actingAs($admin)->post('/teaching-assignments', ['section_id' => $this->sectionA->id, 'subject_id' => $sci->id, 'teacher_id' => $gone->id])->assertNotFound();
        $this->assertSame(1, TeachingAssignment::count());
    }

    // ------------------------------------------------------------------ 39

    public function test_marks_can_be_saved_only_by_the_owner_who_currently_teaches_it_in_a_draft(): void
    {
        $batch = $this->batch();
        $stranger = $this->makeUser('teacher', $this->branchA->id);

        $this->save($this->teacherA, $batch)->assertSessionHasNoErrors();
        $this->assertEquals(50, Mark::first()->score);

        $this->save($stranger, $batch, 99)->assertForbidden();          // not the owner, not assigned
        $this->assertEquals(50, Mark::first()->score);

        // a batch id that belongs to someone else's class, guessed by the browser
        $sectionB2 = \App\Models\Section::create(['branch_id' => $this->branchB->id, 'grade_id' => $this->grade->id, 'academic_year_id' => $this->year->id, 'name' => 'B2']);
        $eastTeacher = $this->makeUser('teacher', $this->branchB->id);
        TeachingAssignment::create(['section_id' => $sectionB2->id, 'subject_id' => $this->math->id, 'teacher_id' => $eastTeacher->id]);
        $this->save($eastTeacher, $batch, 1)->assertForbidden();
    }

    public function test_the_owner_loses_access_when_their_assignment_is_removed(): void
    {
        $batch = $this->batch();
        TeachingAssignment::first()->delete();
        $this->save($this->teacherA, $batch)->assertForbidden();
        $this->actingAs($this->teacherA)->post("/mark-batches/{$batch->id}/submit")->assertForbidden();
    }

    public function test_branch_and_school_scope_is_checked_even_for_the_assigned_owner(): void
    {
        $batch = $this->batch();
        $this->teacherA->update(['branch_id' => $this->branchB->id]);   // moved to another branch
        $this->save($this->teacherA->fresh(), $batch)->assertForbidden();

        $this->teacherA->update(['branch_id' => $this->branchA->id]);
        $owner = $this->owner;   // org-wide, has enter-marks, but holds no assignment
        $this->save($owner, $batch)->assertForbidden();
    }

    public function test_only_draft_batches_accept_marks_and_a_save_racing_a_submit_loses(): void
    {
        $batch = $this->batch();
        $this->save($this->teacherA, $batch)->assertSessionHasNoErrors();
        $this->actingAs($this->teacherA)->post("/mark-batches/{$batch->id}/submit")->assertSessionHasNoErrors();

        $this->save($this->teacherA, $batch, 10)->assertStatus(422);
        $this->assertEquals(50, Mark::first()->score);
        $this->actingAs($this->teacherA)->post("/mark-batches/{$batch->id}/submit")->assertStatus(422);
    }

    public function test_scores_above_the_maximum_and_foreign_enrollments_are_refused(): void
    {
        $batch = $this->batch();
        $e = $this->studentA->currentEnrollment;
        $this->actingAs($this->teacherA)->post("/mark-batches/{$batch->id}", ['records' => [['enrollment_id' => $e->id, 'score' => 120, 'max_score' => 100]]])->assertSessionHasErrors('records.0.score');

        $foreign = $this->studentB->currentEnrollment;   // a student of ANOTHER section
        $this->actingAs($this->teacherA)->post("/mark-batches/{$batch->id}", ['records' => [['enrollment_id' => $foreign->id, 'score' => 50, 'max_score' => 100]]])->assertStatus(422);
        $this->assertSame(0, Mark::count());
    }

    public function test_opening_the_entry_page_cannot_create_a_batch_for_another_schools_or_years_exam(): void
    {
        $otherSchool = \App\Models\School::create(['name' => 'Other', 'code' => 'OTH9']);
        $ey = \App\Models\AcademicYear::create(['school_id' => $otherSchool->id, 'name' => '2026', 'start_date' => '2026-09-01', 'end_date' => '2027-06-30', 'is_current' => true]);
        $et = Term::create(['academic_year_id' => $ey->id, 'name' => 'T', 'start_date' => '2026-09-01', 'end_date' => '2026-12-01']);
        $foreignExam = Exam::create(['school_id' => $otherSchool->id, 'term_id' => $et->id, 'name' => 'X', 'weight_percent' => 100]);

        $this->actingAs($this->teacherA)->get("/marks/{$foreignExam->id}/{$this->sectionA->id}/{$this->math->id}")->assertNotFound();
        $this->assertSame(0, MarkBatch::count());

        $this->actingAs($this->teacherA)->get("/marks/{$this->exam->id}/{$this->sectionA->id}/{$this->math->id}")->assertOk();
        $this->assertSame(1, MarkBatch::count());
    }

    // ------------------------------------------------------------------ 40

    public function test_published_marks_cannot_be_changed_by_any_route(): void
    {
        $approver = $this->makeUser('branch_admin', $this->branchA->id);
        $batch = $this->batch();
        $this->save($this->teacherA, $batch)->assertSessionHasNoErrors();
        $this->toPublished($batch, $approver);
        $mark = Mark::first();

        // the normal routes
        $this->save($this->teacherA, $batch, 99)->assertStatus(422);
        $this->actingAs($this->teacherA)->post("/mark-batches/{$batch->id}/submit")->assertStatus(422);
        $this->actingAs($approver)->post("/mark-batches/{$batch->id}/approve")->assertStatus(422);
        $this->actingAs($approver)->post("/mark-batches/{$batch->id}/send-back")->assertStatus(422);
        $this->actingAs($approver)->post("/mark-batches/{$batch->id}/publish")->assertStatus(422);

        // ...and the model layer, whatever calls it. Each attempt reloads its own fresh copy from the
        // database, so a caught exception from one attempt can't leave a dirty in-memory attribute
        // that changes what the next attempt is actually testing.
        $attempts = [
            'edit the score' => fn () => $mark->fresh()->update(['score' => 1]),
            'add a new score to the batch' => fn () => Mark::create(['mark_batch_id' => $batch->id, 'enrollment_id' => $this->studentB->currentEnrollment->id, 'score' => 5, 'max_score' => 100]),
            'force the batch back to draft' => fn () => $batch->fresh()->update(['status' => 'draft']),
            'change the batch owner' => fn () => $batch->fresh()->update(['teacher_id' => $approver->id]),
            'force the batch to submitted' => fn () => $batch->fresh()->update(['status' => 'submitted']),
        ];

        foreach ($attempts as $label => $try) {
            try {
                $try();
                $this->fail("published results were changed by: {$label}");
            } catch (LockedRecordException) {
                $this->assertTrue(true);
            }
        }
        $this->assertEquals(50, $mark->fresh()->score);
        $this->assertSame('published', $batch->fresh()->status);
    }

    public function test_submitted_and_approved_marks_are_locked_too(): void
    {
        $batch = $this->batch();
        $this->save($this->teacherA, $batch)->assertSessionHasNoErrors();
        $batch->update(['status' => 'submitted']);
        $this->expectException(LockedRecordException::class);
        Mark::first()->update(['score' => 7]);
    }

    public function test_illegal_status_jumps_are_refused(): void
    {
        $batch = $this->batch();
        foreach (['approved', 'published'] as $to) {
            try {
                $batch->update(['status' => $to]);
                $this->fail("draft -> {$to} allowed");
            } catch (LockedRecordException) {
                $this->assertSame('draft', $batch->fresh()->status);
            }
        }
    }

    public function test_reopening_needs_permission_scope_and_a_real_reason_and_keeps_the_old_version(): void
    {
        $approver = $this->makeUser('branch_admin', $this->branchA->id);
        $batch = $this->batch();
        $this->save($this->teacherA, $batch, 50);
        $this->toPublished($batch, $approver);
        $reopen = fn ($u, $reason) => $this->actingAs($u)->post("/mark-batches/{$batch->id}/reopen", ['reason' => $reason]);

        $reopen($this->teacherA, 'I made a mistake')->assertForbidden();                       // no publish-results
        $reopen($this->makeUser('branch_admin', $this->branchB->id), 'Wrong branch reason')->assertForbidden();
        $reopen($approver, '')->assertSessionHasErrors('reason');
        $reopen($approver, 'ok')->assertSessionHasErrors('reason');                            // too short to mean anything
        $this->assertSame('published', $batch->fresh()->status);

        $reopen($approver, 'Score for Almaz was mistyped')->assertSessionHasNoErrors();
        $batch->refresh();
        $this->assertSame('draft', $batch->status);
        $this->assertNull($batch->published_at);

        // the published version is still on record, closed with who/when/why, with the scores as published
        $v1 = MarkBatchPublication::firstOrFail();
        $this->assertSame(1, $v1->version);
        $this->assertSame($approver->id, $v1->published_by);
        $this->assertSame($approver->id, $v1->reopened_by);
        $this->assertNotNull($v1->reopened_at);
        $this->assertSame('Score for Almaz was mistyped', $v1->reopen_reason);
        $this->assertEquals(50, $v1->marks_snapshot[0]['score']);

        $log = AuditLog::where('action', 'mark_batch.reopened')->firstOrFail();
        $this->assertSame('Score for Almaz was mistyped', $log->note);
        $this->assertSame('published', $log->before['status']);
        $this->assertSame(1, $log->before['version']);
        $this->assertSame($approver->id, $log->user_id);

        // the teacher can now correct it; published again = version 2, and version 1 still shows the OLD score
        $this->save($this->teacherA, $batch, 65)->assertSessionHasNoErrors();
        $this->toPublished($batch, $approver);
        $versions = MarkBatchPublication::orderBy('version')->get();
        $this->assertCount(2, $versions);
        $this->assertEquals(50, $versions[0]->marks_snapshot[0]['score']);
        $this->assertEquals(65, $versions[1]->marks_snapshot[0]['score']);
        $this->assertNull($versions[1]->reopened_at, 'the new version is live');
        $this->assertEquals(2, AuditLog::where('action', 'mark_batch.published')->latest('id')->first()->after['version']);
    }

    public function test_publication_records_are_append_only(): void
    {
        $approver = $this->makeUser('branch_admin', $this->branchA->id);
        $batch = $this->batch();
        $this->save($this->teacherA, $batch);
        $this->toPublished($batch, $approver);
        $p = MarkBatchPublication::firstOrFail();

        foreach ([fn () => $p->update(['marks_snapshot' => []]), fn () => $p->update(['published_by' => null]), fn () => $p->delete()] as $try) {
            try {
                $try();
                $this->fail('a publication record was altered');
            } catch (\App\Exceptions\DeletionBlockedException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_exams_of_a_published_term_are_locked_including_their_name(): void
    {
        $approver = $this->makeUser('branch_admin', $this->branchA->id);
        $batch = $this->batch();
        $this->save($this->teacherA, $batch);
        $this->toPublished($batch, $approver);

        $this->actingAs($this->owner)->patch("/exams/{$this->exam->id}", ['name' => 'Renamed', 'type' => 'final', 'weight_percent' => 100])->assertSessionHasErrors('exam');
        $this->assertSame('Final', $this->exam->fresh()->name);
    }
}
