<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\MarkBatch;
use App\Models\Section;
use App\Models\Subject;
use App\Models\TeachingAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

/**
 * Sections (the wizard and the page), teacher assignment and the audit log after the admin redesign. The screens
 * changed; these pin that the rules did not: branch scope, capacity, one section name per grade/branch/year,
 * one teacher per subject per class, and that the audit trail keeps everything it kept before.
 */
class AdminStructureWorkflowTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    private Subject $maths;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchool();
        $this->maths = Subject::create(['school_id' => $this->school->id, 'name' => 'Maths']);
    }

    private function page($r): array
    {
        return json_decode(json_encode($this->props($r)), true);
    }

    private function sectionPayload(array $over = []): array
    {
        return array_merge(['branch_id' => $this->branchA->id, 'grade_id' => $this->grade->id, 'academic_year_id' => $this->year->id, 'name' => 'C', 'capacity' => 30], $over);
    }

    private function admin(array $permissions, ?int $branchId = null): User
    {
        return $this->makeUser('branch_admin', $branchId ?? $this->branchA->id, $permissions);
    }

    // ------------------------------------------------------------------ section wizard

    public function test_the_section_wizard_page_lists_what_exists_and_respects_branch_scope(): void
    {
        $page = $this->page($this->actingAs($this->owner)->get("/sections/create?grade={$this->grade->id}")->assertOk());
        $this->assertSame($this->grade->id, $page['presetGrade']);
        $this->assertCount(2, $page['branches']);
        $this->assertEqualsCanonicalizing([$this->sectionA->id, $this->sectionB->id], array_column($page['existing'], 'id'));

        $mine = $this->page($this->actingAs($this->admin(['manage-classes']))->get('/sections/create')->assertOk());
        $this->assertSame([$this->branchA->id], array_column($mine['branches'], 'id'));
        $this->assertSame([$this->sectionA->id], array_column($mine['existing'], 'id'), 'another branch\'s sections are not even listed');
    }

    public function test_only_people_who_manage_classes_can_open_the_section_wizard(): void
    {
        $this->actingAs($this->makeUser('teacher', $this->branchA->id))->get('/sections/create')->assertForbidden();
        $this->actingAs($this->admin(['manage-students']))->get('/sections/create')->assertForbidden();
    }

    public function test_creating_a_section_reports_what_was_made(): void
    {
        $this->actingAs($this->owner)->post('/sections', $this->sectionPayload())->assertSessionHasNoErrors()->assertSessionHas('section_saved');

        $s = Section::where('name', 'C')->firstOrFail();
        $this->assertSame(30, $s->capacity);
        $this->assertSame(['G1', 'C', 'Main', '2026'], [session('section_saved')['grade'], session('section_saved')['name'], session('section_saved')['branch'], session('section_saved')['academic_year']]);
    }

    public function test_a_section_with_no_capacity_is_allowed(): void
    {
        $this->actingAs($this->owner)->post('/sections', $this->sectionPayload(['capacity' => null]))->assertSessionHasNoErrors();

        $this->assertNull(Section::where('name', 'C')->first()->capacity);
        $this->actingAs($this->owner)->post('/sections', $this->sectionPayload(['name' => 'D', 'capacity' => 0]))->assertSessionHasErrors('capacity');
    }

    public function test_the_same_section_name_twice_is_refused_in_words_but_is_fine_in_another_branch_or_year(): void
    {
        $this->actingAs($this->owner)->post('/sections', $this->sectionPayload(['name' => 'A']))->assertSessionHasErrors('name');
        $this->assertSame(1, Section::where('branch_id', $this->branchA->id)->where('name', 'A')->count());
        $this->assertStringContainsString('already a section called A', session('errors')->first('name'));

        $this->actingAs($this->owner)->post('/sections', $this->sectionPayload(['name' => 'A', 'branch_id' => $this->branchB->id]))->assertSessionHasNoErrors();
        $this->assertSame(1, Section::where('branch_id', $this->branchB->id)->where('name', 'A')->count());
    }

    public function test_a_branch_admin_cannot_create_a_section_in_another_branch_or_without_permission(): void
    {
        $this->actingAs($this->admin(['manage-classes']))->post('/sections', $this->sectionPayload(['branch_id' => $this->branchB->id]))->assertForbidden();
        $this->actingAs($this->admin(['manage-students']))->post('/sections', $this->sectionPayload())->assertForbidden();
        $this->assertNull(Section::where('name', 'C')->first());
    }

    public function test_renaming_into_a_duplicate_or_below_the_number_of_students_is_refused(): void
    {
        $other = Section::create(['branch_id' => $this->branchA->id, 'grade_id' => $this->grade->id, 'academic_year_id' => $this->year->id, 'name' => 'B']);

        $this->actingAs($this->owner)->patch("/sections/{$other->id}", ['name' => 'A', 'capacity' => null])->assertSessionHasErrors('name');
        $this->assertSame('B', $other->fresh()->name);

        $this->actingAs($this->owner)->patch("/sections/{$this->sectionA->id}", ['name' => 'A', 'capacity' => 1])->assertSessionHasNoErrors();
        $this->makeStudent($this->branchA, $this->sectionA, 'Second', 'STU-A2');
        $this->actingAs($this->owner)->patch("/sections/{$this->sectionA->id}", ['name' => 'A', 'capacity' => 1])->assertSessionHasErrors('capacity');
    }

    public function test_the_structure_page_shows_students_and_the_class_teacher(): void
    {
        $t = $this->makeUser('teacher', $this->branchA->id);
        $this->sectionA->update(['class_teacher_id' => $t->id, 'capacity' => 5]);

        $page = $this->page($this->actingAs($this->owner)->get('/academic-structure')->assertOk());
        $a = collect($page['grades'][0]['sections'])->firstWhere('id', $this->sectionA->id);
        $this->assertSame([1, 5, $t->name, true], [$a['students'], $a['capacity'], $a['class_teacher'], $a['is_current_year']]);
        $b = collect($page['grades'][0]['sections'])->firstWhere('id', $this->sectionB->id);
        $this->assertNull($b['class_teacher']);
        $this->assertTrue($page['canAssignTeachers']);
    }

    public function test_a_section_with_students_still_cannot_be_removed(): void
    {
        $this->actingAs($this->owner)->delete("/sections/{$this->sectionA->id}")->assertSessionHasErrors('delete');
        $this->assertNotNull(Section::find($this->sectionA->id));
    }

    // ------------------------------------------------------------------ teacher assignment

    public function test_the_assignment_page_gives_each_class_its_branch_and_each_teacher_theirs(): void
    {
        $tA = $this->makeUser('teacher', $this->branchA->id);
        $tB = $this->makeUser('teacher', $this->branchB->id);
        TeachingAssignment::create(['section_id' => $this->sectionA->id, 'subject_id' => $this->maths->id, 'teacher_id' => $tA->id]);

        $page = $this->page($this->actingAs($this->owner)->get("/teaching-assignments?section={$this->sectionB->id}")->assertOk());
        $this->assertSame($this->sectionB->id, $page['focus']);
        $this->assertTrue($page['canSetClassTeacher']);
        $a = collect($page['sections'])->firstWhere('id', $this->sectionA->id);
        $this->assertSame(['G1', 'A', $this->branchA->id, 1], [$a['grade'], $a['name'], $a['branch_id'], count($a['assignments'])]);
        // The owner appears too, not just role=teacher accounts -- holding enter-marks is what makes
        // someone pickable here, so an owner or branch admin can take over a subject for a teacher who
        // cannot use the system by assigning it to themselves, the same as any other teacher.
        $this->assertEqualsCanonicalizing([[$tA->id, $this->branchA->id], [$tB->id, $this->branchB->id], [$this->owner->id, null]], array_map(fn ($t) => [$t['id'], $t['branch_id']], $page['teachers']));

        $mine = $this->page($this->actingAs($this->admin(['manage-teachers']))->get('/teaching-assignments')->assertOk());
        $this->assertSame([$this->sectionA->id], array_column($mine['sections'], 'id'));
        $this->assertSame([$tA->id], array_column($mine['teachers'], 'id'));
        $this->assertFalse($mine['canSetClassTeacher'], 'changing a class teacher is the classes permission');
    }

    /** Some teachers cannot use the system at all; the principal (owner, or a branch admin holding
     *  enter-marks) must be able to give a subject to THEMSELVES, same as giving it to any other teacher,
     *  so they can enter its marks directly instead of being stuck behind someone who never will. */
    public function test_an_owner_or_admin_with_enter_marks_can_assign_a_subject_to_themselves(): void
    {
        $body = ['section_id' => $this->sectionA->id, 'subject_id' => $this->maths->id, 'teacher_id' => $this->owner->id];
        $this->actingAs($this->owner)->post('/teaching-assignments', $body)->assertSessionHasNoErrors();
        $this->assertSame($this->owner->id, TeachingAssignment::first()->teacher_id);

        TeachingAssignment::query()->delete();
        $admin = $this->admin(['manage-teachers', 'enter-marks']);
        $this->actingAs($admin)->post('/teaching-assignments', ['section_id' => $this->sectionA->id, 'subject_id' => $this->maths->id, 'teacher_id' => $admin->id])->assertSessionHasNoErrors();
        $this->assertSame($admin->id, TeachingAssignment::first()->teacher_id);
    }

    public function test_a_branch_admin_without_enter_marks_cannot_be_given_a_subject(): void
    {
        $admin = $this->admin(['manage-teachers']);
        $r = $this->actingAs($this->owner)->post('/teaching-assignments', ['section_id' => $this->sectionA->id, 'subject_id' => $this->maths->id, 'teacher_id' => $admin->id]);
        $this->assertSame(422, $r->getStatusCode());
        $this->assertSame(0, TeachingAssignment::count());
    }

    // ------------------------------------------------------------------ per-section curriculum

    public function test_the_academic_structure_page_carries_each_sections_subject_ids_and_the_school_catalog(): void
    {
        $science = Subject::create(['school_id' => $this->school->id, 'name' => 'Science']);
        $this->sectionA->subjects()->sync([$this->maths->id]);

        $page = $this->page($this->actingAs($this->owner)->get('/academic-structure')->assertOk());
        $g = collect($page['grades'])->firstWhere('id', $this->grade->id);
        $s = collect($g['sections'])->firstWhere('id', $this->sectionA->id);

        $this->assertSame([$this->maths->id], $s['subject_ids']);
        $this->assertEqualsCanonicalizing([$this->maths->id, $science->id], array_column($page['subjects'], 'id'));
    }

    public function test_an_owner_can_set_a_sections_subjects_and_it_is_audited(): void
    {
        $science = Subject::create(['school_id' => $this->school->id, 'name' => 'Science']);

        $this->actingAs($this->owner)->put("/sections/{$this->sectionA->id}/subjects", ['subject_ids' => [$this->maths->id, $science->id]])->assertSessionHasNoErrors();
        $this->assertEqualsCanonicalizing([$this->maths->id, $science->id], $this->sectionA->subjects()->pluck('subjects.id')->all());

        $this->actingAs($this->owner)->put("/sections/{$this->sectionA->id}/subjects", ['subject_ids' => [$this->maths->id]])->assertSessionHasNoErrors();
        $this->assertSame([$this->maths->id], $this->sectionA->subjects()->pluck('subjects.id')->all());

        $log = AuditLog::where('action', 'section.subjects_set')->latest('id')->firstOrFail();
        $this->assertEqualsCanonicalizing(['Maths', 'Science'], $log->before['subjects']);
        $this->assertSame(['Maths'], $log->after['subjects']);
    }

    public function test_only_manage_classes_can_set_a_sections_subjects_and_only_the_schools_own(): void
    {
        $noPermission = $this->makeUser('branch_admin', $this->branchA->id, []);
        $this->actingAs($noPermission)->put("/sections/{$this->sectionA->id}/subjects", ['subject_ids' => []])->assertForbidden();

        $otherSchool = \App\Models\School::create(['name' => 'Other', 'code' => 'OTH']);
        $otherSubject = Subject::create(['school_id' => $otherSchool->id, 'name' => 'Alien Subject']);
        $this->actingAs($this->owner)->put("/sections/{$this->sectionA->id}/subjects", ['subject_ids' => [$otherSubject->id]])->assertStatus(422);
        $this->assertSame(0, $this->sectionA->subjects()->count());
    }

    public function test_an_unconfigured_section_offers_every_subject_but_a_configured_one_offers_only_its_own(): void
    {
        $science = Subject::create(['school_id' => $this->school->id, 'name' => 'Science']);

        // not configured yet -- both subjects are offered for this class, exactly like before
        $page = $this->page($this->actingAs($this->owner)->get('/teaching-assignments')->assertOk());
        $section = collect($page['sections'])->firstWhere('id', $this->sectionA->id);
        $this->assertEqualsCanonicalizing([$this->maths->id, $science->id], array_column($section['subjects'], 'id'));

        // curated down to Maths only -- this class no longer offers Science
        $this->sectionA->subjects()->sync([$this->maths->id]);
        $page = $this->page($this->actingAs($this->owner)->get('/teaching-assignments')->assertOk());
        $section = collect($page['sections'])->firstWhere('id', $this->sectionA->id);
        $this->assertSame([$this->maths->id], array_column($section['subjects'], 'id'));

        // and giving THAT class a subject outside its own curriculum is refused server-side too
        $teacher = $this->makeUser('teacher', $this->branchA->id);
        $r = $this->actingAs($this->owner)->post('/teaching-assignments', ['section_id' => $this->sectionA->id, 'subject_id' => $science->id, 'teacher_id' => $teacher->id]);
        $this->assertSame(422, $r->getStatusCode());
        $this->assertSame(0, TeachingAssignment::count());

        // Maths itself still works fine
        $this->actingAs($this->owner)->post('/teaching-assignments', ['section_id' => $this->sectionA->id, 'subject_id' => $this->maths->id, 'teacher_id' => $teacher->id])->assertSessionHasNoErrors();
    }

    public function test_a_different_section_of_the_same_grade_keeps_its_own_subjects_unless_applied_to_the_whole_grade(): void
    {
        // BuildsSchool already gives this grade a second section of its own (sectionB, in branchB) --
        // a third one (sectionC) pins that the "whole grade" really does mean every section of it.
        $science = Subject::create(['school_id' => $this->school->id, 'name' => 'Science']);
        $sectionC = Section::create(['branch_id' => $this->branchA->id, 'grade_id' => $this->grade->id, 'academic_year_id' => $this->year->id, 'name' => 'C']);
        $sectionC->subjects()->sync([$science->id]);

        // 1A gets only Maths, saved for 1A alone -- 1C's own Science list must be untouched
        $this->actingAs($this->owner)->put("/sections/{$this->sectionA->id}/subjects", ['subject_ids' => [$this->maths->id]])->assertSessionHasNoErrors();
        $this->assertSame([$this->maths->id], $this->sectionA->subjects()->pluck('subjects.id')->all());
        $this->assertSame([$science->id], $sectionC->subjects()->pluck('subjects.id')->all());

        // now apply Maths to the WHOLE grade -- an org-wide owner reaches every branch's section of it,
        // so 1C's list (and the other-branch sectionB's) is replaced too
        $this->actingAs($this->owner)->put("/sections/{$this->sectionA->id}/subjects", ['subject_ids' => [$this->maths->id], 'apply_to_grade' => true])->assertSessionHasNoErrors();
        $this->assertSame([$this->maths->id], $sectionC->subjects()->pluck('subjects.id')->all());
        $this->assertSame([$this->maths->id], $this->sectionB->subjects()->pluck('subjects.id')->all());

        $log = AuditLog::where('action', 'section.subjects_set')->latest('id')->firstOrFail();
        $this->assertEqualsCanonicalizing([$this->sectionA->id, $this->sectionB->id, $sectionC->id], $log->after['applied_to_sections']);
    }

    public function test_applying_to_the_whole_grade_never_crosses_branches(): void
    {
        $gradeInBranchB = Section::create(['branch_id' => $this->branchB->id, 'grade_id' => $this->grade->id, 'academic_year_id' => $this->year->id, 'name' => 'Z']);

        $this->actingAs($this->admin(['manage-classes']))->put("/sections/{$this->sectionA->id}/subjects", ['subject_ids' => [$this->maths->id], 'apply_to_grade' => true])->assertSessionHasNoErrors();

        $this->assertSame(0, $gradeInBranchB->subjects()->count(), "a branch-scoped admin applying to \"the whole grade\" must never reach another branch's section");
    }

    public function test_giving_a_subject_to_a_teacher_then_replacing_needs_a_deliberate_yes(): void
    {
        $one = $this->makeUser('teacher', $this->branchA->id);
        $two = $this->makeUser('teacher', $this->branchA->id);
        $body = fn ($teacher, $extra = []) => array_merge(['section_id' => $this->sectionA->id, 'subject_id' => $this->maths->id, 'teacher_id' => $teacher->id], $extra);

        $this->actingAs($this->owner)->post('/teaching-assignments', $body($one))->assertSessionHasNoErrors();
        $this->assertSame($one->id, TeachingAssignment::first()->teacher_id);

        $this->actingAs($this->owner)->post('/teaching-assignments', $body($one))->assertSessionHasErrors('teacher_id');       // same teacher: nothing to do
        $this->actingAs($this->owner)->post('/teaching-assignments', $body($two))->assertSessionHasErrors('teacher_id');       // different teacher, not confirmed
        $this->assertSame($one->id, TeachingAssignment::first()->teacher_id);

        $term = \App\Models\Term::create(['academic_year_id' => $this->year->id, 'name' => 'T1', 'start_date' => '2026-09-01', 'end_date' => '2026-12-30']);
        $exam = \App\Models\Exam::create(['school_id' => $this->school->id, 'term_id' => $term->id, 'name' => 'Mid', 'weight_percent' => 100]);
        $draft = MarkBatch::create(['exam_id' => $exam->id, 'section_id' => $this->sectionA->id, 'subject_id' => $this->maths->id, 'teacher_id' => $one->id, 'status' => 'draft']);
        $this->actingAs($this->owner)->post('/teaching-assignments', $body($two, ['replace' => true]))->assertSessionHasNoErrors();

        $this->assertSame($two->id, TeachingAssignment::first()->teacher_id);
        $this->assertSame($two->id, $draft->fresh()->teacher_id, 'an unsent mark sheet moves with the subject');
        $log = AuditLog::where('action', 'teaching_assignment.reassigned')->firstOrFail();
        $this->assertSame($one->name, $log->before['teacher']);
        $this->assertSame($two->name, $log->after['teacher']);
    }

    public function test_a_teacher_from_another_branch_or_an_inactive_one_cannot_be_given_a_class(): void
    {
        $east = $this->makeUser('teacher', $this->branchB->id);
        $gone = $this->makeUser('teacher', $this->branchA->id);
        $gone->update(['status' => 'inactive']);

        foreach ([$east, $gone] as $t) {
            $r = $this->actingAs($this->owner)->post('/teaching-assignments', ['section_id' => $this->sectionA->id, 'subject_id' => $this->maths->id, 'teacher_id' => $t->id]);
            $this->assertContains($r->getStatusCode(), [404, 422]);
        }
        $this->assertSame(0, TeachingAssignment::count());
    }

    public function test_only_people_who_manage_teachers_in_scope_can_assign_or_remove(): void
    {
        $t = $this->makeUser('teacher', $this->branchA->id);
        $body = ['section_id' => $this->sectionA->id, 'subject_id' => $this->maths->id, 'teacher_id' => $t->id];

        $this->actingAs($this->admin(['manage-students']))->post('/teaching-assignments', $body)->assertForbidden();
        $this->actingAs($this->admin(['manage-teachers'], $this->branchB->id))->post('/teaching-assignments', $body)->assertForbidden();
        $this->assertSame(0, TeachingAssignment::count());

        $this->actingAs($this->admin(['manage-teachers']))->post('/teaching-assignments', $body)->assertSessionHasNoErrors();
        $a = TeachingAssignment::firstOrFail();
        $this->actingAs($this->admin(['manage-teachers'], $this->branchB->id))->delete("/teaching-assignments/{$a->id}")->assertForbidden();
        $this->actingAs($this->admin(['manage-teachers']))->delete("/teaching-assignments/{$a->id}")->assertSessionHasNoErrors();
        $this->assertSame(0, TeachingAssignment::count());
    }

    public function test_the_class_teacher_must_be_a_teacher_of_that_branch(): void
    {
        $tA = $this->makeUser('teacher', $this->branchA->id);
        $tB = $this->makeUser('teacher', $this->branchB->id);

        $this->actingAs($this->owner)->patch("/sections/{$this->sectionA->id}/class-teacher", ['class_teacher_id' => $tA->id])->assertSessionHasNoErrors();
        $this->assertSame($tA->id, $this->sectionA->fresh()->class_teacher_id);

        $this->actingAs($this->owner)->patch("/sections/{$this->sectionA->id}/class-teacher", ['class_teacher_id' => $tB->id])->assertNotFound();
        $this->assertSame($tA->id, $this->sectionA->fresh()->class_teacher_id);

        $this->actingAs($this->admin(['manage-teachers']))->patch("/sections/{$this->sectionA->id}/class-teacher", ['class_teacher_id' => null])->assertForbidden();
        $this->actingAs($this->owner)->patch("/sections/{$this->sectionA->id}/class-teacher", ['class_teacher_id' => null]);
        $this->assertNull($this->sectionA->fresh()->class_teacher_id);
    }

    // ------------------------------------------------------------------ audit log

    public function test_the_audit_log_keeps_every_field_and_the_filters_and_export_still_work(): void
    {
        $this->actingAs($this->owner)->post('/staff', ['name' => 'New Person', 'email' => 'np@test.test', 'phone' => '0911000444', 'title' => 'Registrar', 'role' => 'branch_admin', 'branch_id' => $this->branchA->id, 'permissions' => ['manage-students']]);

        $page = $this->page($this->actingAs($this->owner)->get('/audit-log')->assertOk());
        $row = collect($page['logs']['data'])->firstWhere('action', 'staff.role_assigned');
        foreach (['id', 'action', 'actor', 'auditable_type', 'auditable_id', 'subject_label', 'before', 'after', 'note', 'ip_address', 'created_at'] as $k) {
            $this->assertArrayHasKey($k, $row);
        }
        $this->assertSame('branch_admin', $row['after']['role']);
        $this->assertArrayHasKey('total', $page['logs']);
        $this->assertArrayHasKey('next_page_url', $page['logs']);
        $this->assertContains('staff.role_assigned', $page['actions']);

        $only = $this->page($this->actingAs($this->owner)->get('/audit-log?action=staff.role_assigned')->assertOk());
        $this->assertSame(['staff.role_assigned'], array_unique(array_column($only['logs']['data'], 'action')));
        $this->actingAs($this->owner)->get('/audit-log/export?action=staff.role_assigned')->assertOk();
    }

    public function test_only_people_who_may_view_the_audit_log_can_see_it_and_only_their_schools_entries(): void
    {
        $this->actingAs($this->makeUser('teacher', $this->branchA->id))->get('/audit-log')->assertForbidden();
        $this->actingAs($this->admin(['manage-students']))->get('/audit-log/export')->assertForbidden();

        $other = \App\Models\School::create(['name' => 'Other', 'code' => 'OTH1']);
        AuditLog::create(['school_id' => $other->id, 'action' => 'secret.other', 'auditable_type' => User::class, 'auditable_id' => 1]);
        $auditor = $this->makeUser('auditor', null);
        $page = $this->page($this->actingAs($auditor)->get('/audit-log')->assertOk());
        $this->assertNotContains('secret.other', array_column($page['logs']['data'], 'action'));
    }
}
