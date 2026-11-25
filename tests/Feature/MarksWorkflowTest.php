<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\Mark;
use App\Models\MarkBatch;
use App\Models\Subject;
use App\Models\TeachingAssignment;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

/**
 * What the teacher's marks screen relies on (the screen itself was redesigned to one student at a time; the
 * academic workflow -- draft, submit, approve, publish, who may enter, what a blank means -- did not change):
 * the page's facts, a save that always carries the WHOLE class list, validation, locked states, access, counts.
 */
class MarksWorkflowTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    private Subject $math;
    private Exam $exam;
    private User $teacher;
    private Enrollment $eA;
    private Enrollment $eB;
    private Enrollment $eC;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchool();
        $this->math = Subject::create(['school_id' => $this->school->id, 'name' => 'Math', 'code' => 'M']);
        $term = Term::create(['academic_year_id' => $this->year->id, 'name' => 'T1', 'start_date' => '2026-09-01', 'end_date' => '2026-12-20']);
        $this->exam = Exam::create(['school_id' => $this->school->id, 'term_id' => $term->id, 'name' => 'Mid-term', 'weight_percent' => 100]);
        $this->teacher = $this->makeUser('teacher', $this->branchA->id, ['enter-marks']);
        TeachingAssignment::create(['section_id' => $this->sectionA->id, 'subject_id' => $this->math->id, 'teacher_id' => $this->teacher->id]);
        $second = $this->makeStudent($this->branchA, $this->sectionA, 'Second', 'STU-A2');
        $third = $this->makeStudent($this->branchA, $this->sectionA, 'Third', 'STU-A3');
        $this->eA = $this->studentA->currentEnrollment;
        $this->eB = $second->currentEnrollment;
        $this->eC = $third->currentEnrollment;
    }

    private function url(): string
    {
        return "/marks/{$this->exam->id}/{$this->sectionA->id}/{$this->math->id}";
    }

    private function page(?User $as = null): array
    {
        return $this->props($this->actingAs($as ?? $this->teacher)->get($this->url())->assertOk());
    }

    private function batch(): MarkBatch
    {
        $this->actingAs($this->teacher)->get($this->url());

        return MarkBatch::firstOrFail();
    }

    /** A save the way the screen sends it: every student in the class, blanks as null. */
    private function saveAll(MarkBatch $b, array $scores, float $max = 100, ?User $as = null)
    {
        $records = [];
        foreach ([$this->eA, $this->eB, $this->eC] as $i => $e) {
            $records[] = ['enrollment_id' => $e->id, 'score' => $scores[$i] ?? null, 'max_score' => $max];
        }

        return $this->actingAs($as ?? $this->teacher)->post("/mark-batches/{$b->id}", ['records' => $records]);
    }

    // ------------------------------------------------------------------ the page

    public function test_the_page_carries_every_fact_the_screen_shows_and_invents_no_marks(): void
    {
        $page = $this->page();

        $this->assertSame('draft', $page['batch']['status']);
        $this->assertSame(100.0, $page['batch']['max_score']);
        $this->assertSame('Mid-term', $page['exam']['name']);
        $this->assertSame('Math', $page['subject']['name']);
        $this->assertCount(3, $page['roster']);
        $this->assertSame([null, null, null], collect($page['roster'])->pluck('score')->all());
        $this->assertContains('STU-A2', collect($page['roster'])->pluck('student_id_number')->all());
        $this->assertSame('exclude', $page['missing_mark_policy']);
    }

    public function test_the_schools_missing_mark_policy_is_passed_so_the_screen_can_explain_it(): void
    {
        $this->school->update(['missing_mark_policy' => 'zero']);

        $this->assertSame('zero', $this->page()['missing_mark_policy']);
    }

    public function test_existing_marks_come_back_and_the_class_maximum_is_the_saved_one_not_the_first_students(): void
    {
        $b = $this->batch();
        // the first student (alphabetically Almaz) is left blank; the others were marked out of 50
        $this->saveAll($b, [null, 40, 25], 50)->assertSessionHasNoErrors();

        $page = $this->page();
        $byName = collect($page['roster'])->keyBy('student_id_number');

        $this->assertSame(50.0, $page['batch']['max_score'], 'not 100 just because the first student has no mark yet');
        $this->assertEquals(40, $byName['STU-A2']['score']);
        $this->assertNull($byName['STU-A']['score']);
    }

    // ------------------------------------------------------------------ saving

    public function test_a_save_keeps_a_row_for_every_student_so_a_blank_is_a_missing_mark_not_not_applicable(): void
    {
        $b = $this->batch();
        $this->saveAll($b, [80, null, null])->assertSessionHasNoErrors();

        $this->assertSame(3, Mark::count(), 'blank students still have a row (score null): the missing-mark policy applies to them');
        $this->assertNull(Mark::where('enrollment_id', $this->eB->id)->first()->score);
        $this->assertEquals(80, Mark::where('enrollment_id', $this->eA->id)->first()->score);
    }

    public function test_saving_again_updates_and_audits_only_what_changed(): void
    {
        $b = $this->batch();
        $this->saveAll($b, [80, 60, null]);
        $audits = AuditLog::where('action', 'mark.updated')->count();

        $this->saveAll($b, [80, 60, null]);
        $this->assertSame($audits, AuditLog::where('action', 'mark.updated')->count(), 'an unchanged re-save writes no audit entries');

        $this->saveAll($b, [80, 65, null])->assertSessionHasNoErrors();
        $this->assertSame($audits + 1, AuditLog::where('action', 'mark.updated')->count());
        $this->assertEquals(65, Mark::where('enrollment_id', $this->eB->id)->first()->score);
        $this->assertSame(3, Mark::count());
    }

    public function test_decimals_and_zero_are_valid_marks(): void
    {
        $b = $this->batch();
        $this->saveAll($b, [87.5, 0, 100])->assertSessionHasNoErrors();

        $this->assertEquals(87.5, Mark::where('enrollment_id', $this->eA->id)->first()->score);
        $this->assertEquals(0, Mark::where('enrollment_id', $this->eB->id)->first()->score);
    }

    // ------------------------------------------------------------------ invalid marks

    public function test_invalid_marks_are_refused_and_nothing_is_saved(): void
    {
        $b = $this->batch();

        $this->saveAll($b, [101])->assertSessionHasErrors('records.0.score');            // above the maximum
        $this->saveAll($b, [-1])->assertSessionHasErrors('records.0.score');             // negative
        $this->saveAll($b, ['abc'])->assertSessionHasErrors('records.0.score');          // not a number
        $this->saveAll($b, [1000], 999.99)->assertSessionHasErrors('records.0.score');   // beyond what the column holds
        $this->saveAll($b, [10], 0)->assertSessionHasErrors('records.0.max_score');      // a maximum of zero
        $this->saveAll($b, [10], 1000)->assertSessionHasErrors('records.0.max_score');   // a maximum the column cannot hold (used to be a database error)

        $this->assertSame(0, Mark::count());
    }

    public function test_a_higher_mark_than_the_maximum_is_refused_even_if_the_maximum_was_lowered(): void
    {
        $b = $this->batch();
        $this->saveAll($b, [90, 80, 70])->assertSessionHasNoErrors();

        $this->saveAll($b, [90, 80, 70], 60)->assertSessionHasErrors('records.0.score');

        $this->assertEquals(100, Mark::first()->max_score, 'nothing was half-saved');
    }

    // ------------------------------------------------------------------ states: submission, approval, publication

    public function test_marks_can_be_submitted_only_from_draft_and_are_then_locked_but_still_readable(): void
    {
        $b = $this->batch();
        $this->saveAll($b, [80, 70, 60]);

        $this->actingAs($this->teacher)->post("/mark-batches/{$b->id}/submit")->assertSessionHasNoErrors();
        $this->assertSame('submitted', $b->refresh()->status);

        $this->saveAll($b, [1, 1, 1])->assertStatus(422);
        $this->actingAs($this->teacher)->post("/mark-batches/{$b->id}/submit")->assertStatus(422);

        $page = $this->page();
        $this->assertSame('submitted', $page['batch']['status']);
        $this->assertEquals(80, collect($page['roster'])->firstWhere('student_id_number', 'STU-A')['score'], 'the saved values are still shown');
        $this->assertEquals(80, Mark::where('enrollment_id', $this->eA->id)->first()->score);
    }

    public function test_approved_and_published_marks_stay_locked_and_show_their_state(): void
    {
        $b = $this->batch();
        $this->saveAll($b, [80, 70, 60]);
        $approver = $this->makeUser('branch_admin', $this->branchA->id, ['approve-results', 'publish-results']);
        $this->actingAs($this->teacher)->post("/mark-batches/{$b->id}/submit");

        $this->actingAs($approver)->post("/mark-batches/{$b->id}/approve")->assertSessionHasNoErrors();
        $this->assertSame('approved', $this->page()['batch']['status']);
        $this->saveAll($b, [1, 1, 1])->assertStatus(422);

        $this->actingAs($approver)->post("/mark-batches/{$b->id}/publish")->assertSessionHasNoErrors();
        $this->assertSame('published', $this->page()['batch']['status']);
        $this->saveAll($b, [1, 1, 1])->assertStatus(422);
        $this->assertEquals(80, Mark::where('enrollment_id', $this->eA->id)->first()->score);
    }

    public function test_an_approver_can_view_the_actual_scores_before_approving_but_never_edit_them(): void
    {
        $b = $this->batch();
        $this->saveAll($b, [80, 70, 60]);
        $approver = $this->makeUser('branch_admin', $this->branchA->id, ['approve-results']);
        $this->actingAs($this->teacher)->post("/mark-batches/{$b->id}/submit");

        $page = $this->props($this->actingAs($approver)->get("/mark-batches/{$b->id}/view")->assertOk());

        $this->assertTrue($page['readOnly']);
        $this->assertSame('submitted', $page['batch']['status']);
        $this->assertEquals(80, collect($page['roster'])->firstWhere('student_id_number', 'STU-A')['score']);

        // No save/submit route exists for this view -- the only route an approver's page can reach is
        // the one that renders it; posting directly to store() still correctly refuses a non-teacher.
        $this->actingAs($approver)->post("/mark-batches/{$b->id}", ['records' => []])->assertStatus(403);
    }

    public function test_viewing_for_approval_still_requires_approve_or_publish_permission(): void
    {
        $b = $this->batch();
        $this->saveAll($b, [80, 70, 60]);
        $this->actingAs($this->teacher)->post("/mark-batches/{$b->id}/submit");
        $bystander = $this->makeUser('teacher', $this->branchA->id, []);

        $this->actingAs($bystander)->get("/mark-batches/{$b->id}/view")->assertForbidden();
    }

    public function test_a_batch_sent_back_is_a_draft_again_with_its_marks_shown(): void
    {
        $b = $this->batch();
        $this->saveAll($b, [80, 70, 60]);
        $approver = $this->makeUser('branch_admin', $this->branchA->id, ['approve-results']);
        $this->actingAs($this->teacher)->post("/mark-batches/{$b->id}/submit");
        $this->actingAs($approver)->post("/mark-batches/{$b->id}/send-back")->assertSessionHasNoErrors();

        $page = $this->page();
        $this->assertSame('draft', $page['batch']['status']);
        $this->assertEquals(70, collect($page['roster'])->firstWhere('student_id_number', 'STU-A2')['score']);
        $this->saveAll($b, [85, 70, 60])->assertSessionHasNoErrors();
    }

    // ------------------------------------------------------------------ the class list and its class page

    public function test_the_class_list_reports_one_card_per_class_with_its_subject_count_and_progress(): void
    {
        $b = $this->batch();
        $this->saveAll($b, [80, null, 60]);

        $classes = $this->props($this->actingAs($this->teacher)->get('/marks'))['classes'];
        $this->assertCount(1, $classes);
        $this->assertSame(
            [$this->sectionA->id, 1, 3, false],
            [$classes[0]['section_id'], $classes[0]['subjects'], $classes[0]['students'], $classes[0]['is_homeroom']]
        );
        $this->assertSame(0, $classes[0]['done'], 'still a draft -- not counted as done yet');

        $this->actingAs($this->teacher)->post("/mark-batches/{$b->id}/submit");
        $classes = $this->props($this->actingAs($this->teacher)->get('/marks'))['classes'];
        $this->assertSame(1, $classes[0]['done'], 'submitted now counts toward done');
    }

    public function test_the_class_list_shows_only_the_teachers_own_classes(): void
    {
        $other = $this->makeUser('teacher', $this->branchA->id, ['enter-marks']);

        $this->assertSame([], $this->props($this->actingAs($other)->get('/marks'))['classes']);
    }

    public function test_the_class_page_lists_each_subject_with_an_exam_row_per_exam(): void
    {
        $b = $this->batch();
        $this->saveAll($b, [80, null, 60]);

        $page = $this->props($this->actingAs($this->teacher)->get("/marks/section/{$this->sectionA->id}")->assertOk());
        $this->assertFalse($page['isHomeroom']);
        $this->assertSame('Math', $page['subjects'][0]['subject_name']);
        $row = $page['subjects'][0]['exams'][0];
        $this->assertSame([3, 2, 'draft'], [$row['students'], $row['entered'], $row['status']]);
    }

    public function test_the_class_page_is_forbidden_to_someone_with_no_reason_to_be_in_this_class(): void
    {
        $stranger = $this->makeUser('teacher', $this->branchA->id, ['enter-marks']);

        $this->actingAs($stranger)->get("/marks/section/{$this->sectionA->id}")->assertForbidden();
    }

    // ------------------------------------------------------------------ homeroom teachers covering a colleague

    public function test_the_homeroom_teacher_sees_and_may_enter_every_subject_of_their_own_class_even_unassigned_ones(): void
    {
        $homeroom = $this->makeUser('teacher', $this->branchA->id, ['enter-marks']);
        $this->sectionA->update(['class_teacher_id' => $homeroom->id]);

        $classes = $this->props($this->actingAs($homeroom)->get('/marks'))['classes'];
        $this->assertSame([$this->sectionA->id, 1, true], [$classes[0]['section_id'], $classes[0]['subjects'], $classes[0]['is_homeroom']]);

        $page = $this->props($this->actingAs($homeroom)->get("/marks/section/{$this->sectionA->id}")->assertOk());
        $this->assertSame('Math', $page['subjects'][0]['subject_name']);

        // not the assigned teacher, but the homeroom teacher of this exact class -- may open and save
        $this->actingAs($homeroom)->get($this->url())->assertOk();
        $this->saveAll($this->batch(), [90, 85, 75], 100, $homeroom)->assertSessionHasNoErrors();
        $this->assertEquals(90, Mark::where('enrollment_id', $this->eA->id)->first()->score);
    }

    /** The exact gap a real school hit: a principal picks a class's curriculum (Section::subjects())
     *  before any teacher is actually assigned to any of it -- the homeroom teacher must still see and
     *  be able to fill in every one of those subjects, not just ones that happen to already have a
     *  TeachingAssignment row (to someone, anyone) sitting underneath them. */
    public function test_the_homeroom_teacher_sees_curriculum_subjects_that_have_no_teacher_assigned_at_all(): void
    {
        $science = Subject::create(['school_id' => $this->school->id, 'name' => 'Science']);
        $this->sectionA->subjects()->sync([$this->math->id, $science->id]); // Math already has $this->teacher; Science has NOBODY
        $homeroom = $this->makeUser('teacher', $this->branchA->id, ['enter-marks']);
        $this->sectionA->update(['class_teacher_id' => $homeroom->id]);

        $classes = $this->props($this->actingAs($homeroom)->get('/marks'))['classes'];
        $this->assertSame(2, $classes[0]['subjects'], 'both Math and the teacher-less Science should show');

        $page = $this->props($this->actingAs($homeroom)->get("/marks/section/{$this->sectionA->id}")->assertOk());
        $this->assertEqualsCanonicalizing(['Math', 'Science'], collect($page['subjects'])->pluck('subject_name')->all());

        // and the homeroom teacher can actually open and save Science, which nobody was ever assigned to teach
        $this->actingAs($homeroom)->get("/marks/{$this->exam->id}/{$this->sectionA->id}/{$science->id}")->assertOk();
        $batch = MarkBatch::where('subject_id', $science->id)->firstOrFail();
        $this->assertSame($homeroom->id, $batch->teacher_id, 'opening it for the first time makes the homeroom teacher its owner');
    }

    public function test_a_homeroom_teacher_covering_a_draft_becomes_its_acting_teacher_and_it_is_audited(): void
    {
        $homeroom = $this->makeUser('teacher', $this->branchA->id, ['enter-marks']);
        $this->sectionA->update(['class_teacher_id' => $homeroom->id]);
        $b = $this->batch(); // created owned by $this->teacher, the regularly assigned subject teacher

        $this->saveAll($b, [90, 85, 75], 100, $homeroom)->assertSessionHasNoErrors();

        $this->assertSame($homeroom->id, $b->refresh()->teacher_id);
        $covered = AuditLog::where('action', 'mark_batch.covered')->first();
        $this->assertNotNull($covered);
        $this->assertSame($homeroom->id, $covered->user_id);
    }

    public function test_a_homeroom_teacher_of_a_different_class_may_not_cover_this_one(): void
    {
        $otherSection = \App\Models\Section::create(['branch_id' => $this->branchA->id, 'grade_id' => $this->grade->id, 'academic_year_id' => $this->year->id, 'name' => 'C']);
        $otherHomeroom = $this->makeUser('teacher', $this->branchA->id, ['enter-marks']);
        $otherSection->update(['class_teacher_id' => $otherHomeroom->id]);

        $this->actingAs($otherHomeroom)->get($this->url())->assertForbidden();
        // their OWN class (curriculum unconfigured, so it permissively falls back to every school
        // subject) is fine to see -- what must never happen is seeing $this->sectionA among them
        $classes = $this->props($this->actingAs($otherHomeroom)->get('/marks'))['classes'];
        $this->assertNotContains($this->sectionA->id, array_column($classes, 'section_id'));
    }

    // ------------------------------------------------------------------ access

    public function test_only_the_assigned_teacher_can_open_or_save(): void
    {
        $b = $this->batch();
        $stranger = $this->makeUser('teacher', $this->branchA->id, ['enter-marks']);
        $noPermission = $this->makeUser('teacher', $this->branchA->id, []);
        $eastTeacher = $this->makeUser('teacher', $this->branchB->id, ['enter-marks']);
        $admin = $this->makeUser('branch_admin', $this->branchA->id, ['enter-marks']);

        foreach ([$stranger, $noPermission, $eastTeacher, $admin] as $u) {
            $this->actingAs($u)->get($this->url())->assertForbidden();
            $this->saveAll($b, [50], 100, $u)->assertForbidden();
            $this->actingAs($u)->post("/mark-batches/{$b->id}/submit")->assertForbidden();
        }
        $this->assertSame(0, Mark::count());
        $this->actingAs($noPermission)->get('/marks')->assertForbidden();
    }
}
