<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Subject;
use App\Models\TeachingAssignment;
use App\Notifications\AssignmentDueSoon;
use App\Notifications\AssignmentPublished;
use App\Support\SchoolCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

/**
 * Academic expansion, Phase 8: "a simple foundation for teacher assignments" (the brief's own words).
 * `manage-assignments` already existed, reserved and unused, since the original base spec -- the critical
 * thing this phase must get right is the live re-authorization (a teacher who no longer teaches a class
 * loses access to its assignments immediately, the same principle `MarkBatchPolicy::enterMarks` already
 * established) and that nothing is ever told to a guardian about a DRAFT.
 */
class AssignmentTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    private Subject $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchool();
        $this->subject = Subject::create(['school_id' => $this->school->id, 'name' => 'Math', 'code' => 'MATH']);
        Storage::fake('local');
    }

    private function teach(\App\Models\User $teacher, \App\Models\Section $section): TeachingAssignment
    {
        return TeachingAssignment::create(['teacher_id' => $teacher->id, 'section_id' => $section->id, 'subject_id' => $this->subject->id]);
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'title' => 'Chapter 3 exercises', 'description' => 'Do the odd numbers.',
            'due_date' => now()->addDays(3)->toDateString(), 'status' => 'draft',
        ], $over);
    }

    public function test_manage_assignments_is_required(): void
    {
        $teacher = $this->makeUser('teacher', $this->branchA->id, []);
        $this->teach($teacher, $this->sectionA);

        $this->actingAs($teacher)->get('/assignments')->assertForbidden();
        $this->actingAs($teacher)->post('/assignments', $this->payload(['section_id' => $this->sectionA->id, 'subject_id' => $this->subject->id]))->assertForbidden();
    }

    public function test_a_teacher_can_create_a_draft_for_their_own_class_and_subject(): void
    {
        Notification::fake();
        $teacher = $this->makeUser('teacher', $this->branchA->id, ['manage-assignments']);
        $this->teach($teacher, $this->sectionA);

        $this->actingAs($teacher)->post('/assignments', $this->payload([
            'section_id' => $this->sectionA->id, 'subject_id' => $this->subject->id,
        ]))->assertSessionHasNoErrors();

        $assignment = Assignment::firstOrFail();
        $this->assertSame('draft', $assignment->status);
        $this->assertSame($teacher->id, $assignment->teacher_id);
        Notification::assertNothingSent();
    }

    public function test_a_teacher_cannot_create_for_a_class_they_do_not_teach(): void
    {
        $teacher = $this->makeUser('teacher', $this->branchA->id, ['manage-assignments']);
        // holds no teaching assignment at all

        $this->actingAs($teacher)->post('/assignments', $this->payload([
            'section_id' => $this->sectionA->id, 'subject_id' => $this->subject->id,
        ]))->assertForbidden();
        $this->assertSame(0, Assignment::count());
    }

    public function test_branch_admin_can_create_for_any_class_in_their_branch_without_holding_the_teaching_assignment(): void
    {
        $teacher = $this->makeUser('teacher', $this->branchA->id, []);
        $this->teach($teacher, $this->sectionA);
        $admin = $this->makeUser('branch_admin', $this->branchA->id, ['manage-assignments']);

        $this->actingAs($admin)->post('/assignments', $this->payload([
            'section_id' => $this->sectionA->id, 'subject_id' => $this->subject->id,
        ]))->assertSessionHasNoErrors();

        $assignment = Assignment::firstOrFail();
        $this->assertSame($teacher->id, $assignment->teacher_id, 'credited to the actual subject teacher, not the admin who clicked save');
    }

    public function test_branch_scoping_refuses_another_branchs_section(): void
    {
        $adminA = $this->makeUser('branch_admin', $this->branchA->id, ['manage-assignments']);
        $teacherB = $this->makeUser('teacher', $this->branchB->id, []);
        $this->teach($teacherB, $this->sectionB);

        $this->actingAs($adminA)->post('/assignments', $this->payload([
            'section_id' => $this->sectionB->id, 'subject_id' => $this->subject->id,
        ]))->assertForbidden();
    }

    public function test_no_teacher_currently_assigned_refuses_creation(): void
    {
        $admin = $this->makeUser('branch_admin', $this->branchA->id, ['manage-assignments']);

        $this->actingAs($admin)->post('/assignments', $this->payload([
            'section_id' => $this->sectionA->id, 'subject_id' => $this->subject->id,
        ]))->assertStatus(422);
        $this->assertSame(0, Assignment::count());
    }

    public function test_publishing_notifies_each_enrolled_students_guardians_exactly_once(): void
    {
        $teacher = $this->makeUser('teacher', $this->branchA->id, ['manage-assignments']);
        $this->teach($teacher, $this->sectionA);
        $parent = $this->makeParent($this->studentA);

        Notification::fake();
        $this->actingAs($teacher)->post('/assignments', $this->payload([
            'section_id' => $this->sectionA->id, 'subject_id' => $this->subject->id, 'status' => 'published',
        ]))->assertSessionHasNoErrors();

        $assignment = Assignment::firstOrFail();
        $this->assertSame('published', $assignment->status);
        $this->assertNotNull($assignment->published_at);
        Notification::assertSentToTimes($parent, AssignmentPublished::class, 1);
    }

    public function test_editing_a_published_assignment_does_not_renotify(): void
    {
        $teacher = $this->makeUser('teacher', $this->branchA->id, ['manage-assignments']);
        $this->teach($teacher, $this->sectionA);
        $parent = $this->makeParent($this->studentA);

        $this->actingAs($teacher)->post('/assignments', $this->payload([
            'section_id' => $this->sectionA->id, 'subject_id' => $this->subject->id, 'status' => 'published',
        ]));
        $assignment = Assignment::firstOrFail();

        Notification::fake();
        $this->actingAs($teacher)->put("/assignments/{$assignment->id}", $this->payload([
            'title' => 'Chapter 3 exercises (corrected)', 'status' => 'published',
        ]))->assertSessionHasNoErrors();

        $assignment->refresh();
        $this->assertSame('Chapter 3 exercises (corrected)', $assignment->title);
        Notification::assertNothingSent();
    }

    public function test_a_draft_never_appears_to_a_guardian(): void
    {
        $teacher = $this->makeUser('teacher', $this->branchA->id, ['manage-assignments']);
        $this->teach($teacher, $this->sectionA);
        $parent = $this->makeParent($this->studentA);

        $this->actingAs($teacher)->post('/assignments', $this->payload([
            'section_id' => $this->sectionA->id, 'subject_id' => $this->subject->id, 'status' => 'draft',
        ]));

        $props = $this->props($this->actingAs($parent)->get('/my/homework'));
        $this->assertCount(0, $props['assignments']);
    }

    public function test_a_guardian_only_sees_their_own_childs_section(): void
    {
        $teacherA = $this->makeUser('teacher', $this->branchA->id, ['manage-assignments']);
        $this->teach($teacherA, $this->sectionA);
        $teacherB = $this->makeUser('teacher', $this->branchB->id, ['manage-assignments']);
        $this->teach($teacherB, $this->sectionB);

        $this->actingAs($teacherA)->post('/assignments', $this->payload([
            'section_id' => $this->sectionA->id, 'subject_id' => $this->subject->id, 'status' => 'published', 'title' => 'For A',
        ]));
        $this->actingAs($teacherB)->post('/assignments', $this->payload([
            'section_id' => $this->sectionB->id, 'subject_id' => $this->subject->id, 'status' => 'published', 'title' => 'For B',
        ]));

        $parentA = $this->makeParent($this->studentA);
        $props = $this->props($this->actingAs($parentA)->get('/my/homework'));
        $this->assertCount(1, $props['assignments']);
        $this->assertSame('For A', $props['assignments'][0]['title']);
    }

    public function test_an_attachment_can_be_uploaded_and_only_an_authorized_viewer_can_open_it(): void
    {
        $teacher = $this->makeUser('teacher', $this->branchA->id, ['manage-assignments']);
        $this->teach($teacher, $this->sectionA);
        $parent = $this->makeParent($this->studentA);

        $this->actingAs($teacher)->post('/assignments', $this->payload([
            'section_id' => $this->sectionA->id, 'subject_id' => $this->subject->id, 'status' => 'published',
            'attachment' => UploadedFile::fake()->image('worksheet.jpg'),
        ]));
        $assignment = Assignment::firstOrFail();
        $this->assertNotNull($assignment->attachment_path);

        $this->actingAs($teacher)->get("/assignments/{$assignment->id}/document")->assertOk();
        $this->actingAs($parent)->get("/assignments/{$assignment->id}/document")->assertOk();

        $unrelatedTeacher = $this->makeUser('teacher', $this->branchA->id, ['manage-assignments']);
        $this->actingAs($unrelatedTeacher)->get("/assignments/{$assignment->id}/document")->assertForbidden();

        $unrelatedParent = $this->makeParent($this->studentB);
        $this->actingAs($unrelatedParent)->get("/assignments/{$assignment->id}/document")->assertForbidden();
    }

    public function test_a_teacher_who_loses_the_teaching_assignment_can_no_longer_edit(): void
    {
        $teacherA = $this->makeUser('teacher', $this->branchA->id, ['manage-assignments']);
        $ta = $this->teach($teacherA, $this->sectionA);

        $this->actingAs($teacherA)->post('/assignments', $this->payload([
            'section_id' => $this->sectionA->id, 'subject_id' => $this->subject->id,
        ]));
        $assignment = Assignment::firstOrFail();

        $teacherB = $this->makeUser('teacher', $this->branchA->id, ['manage-assignments']);
        $ta->update(['teacher_id' => $teacherB->id]);

        $this->actingAs($teacherA)->put("/assignments/{$assignment->id}", $this->payload(['title' => 'Should not land']))->assertForbidden();
        $this->assertSame('Chapter 3 exercises', $assignment->fresh()->title);

        $this->actingAs($teacherB)->put("/assignments/{$assignment->id}", $this->payload(['title' => 'Now mine to edit']))->assertSessionHasNoErrors();
        $this->assertSame('Now mine to edit', $assignment->fresh()->title);
    }

    public function test_the_due_soon_command_notifies_once_and_is_guarded(): void
    {
        $teacher = $this->makeUser('teacher', $this->branchA->id, ['manage-assignments']);
        $this->teach($teacher, $this->sectionA);
        $parent = $this->makeParent($this->studentA);

        $tomorrow = SchoolCalendar::today()->addDay()->toDateString();
        $this->actingAs($teacher)->post('/assignments', $this->payload([
            'section_id' => $this->sectionA->id, 'subject_id' => $this->subject->id,
            'status' => 'published', 'due_date' => $tomorrow,
        ]));
        $assignment = Assignment::firstOrFail();

        Notification::fake();
        Artisan::call('assignments:notify-due-soon');
        Notification::assertSentToTimes($parent, AssignmentDueSoon::class, 1);
        $this->assertNotNull($assignment->fresh()->due_soon_notified_at);

        Notification::fake();
        Artisan::call('assignments:notify-due-soon');
        Notification::assertNothingSent();
    }

    public function test_there_is_no_way_to_delete_someone_elses_assignment(): void
    {
        $teacherA = $this->makeUser('teacher', $this->branchA->id, ['manage-assignments']);
        $this->teach($teacherA, $this->sectionA);
        $this->actingAs($teacherA)->post('/assignments', $this->payload([
            'section_id' => $this->sectionA->id, 'subject_id' => $this->subject->id,
        ]));
        $assignment = Assignment::firstOrFail();

        $teacherB = $this->makeUser('teacher', $this->branchA->id, ['manage-assignments']);
        $this->actingAs($teacherB)->delete("/assignments/{$assignment->id}")->assertForbidden();
        $this->assertSame(1, Assignment::count());
    }

    public function test_someone_with_no_permission_at_all_is_refused_everything(): void
    {
        $registrar = $this->makeUser('branch_admin', $this->branchA->id, ['manage-students']);

        $this->actingAs($registrar)->get('/assignments')->assertForbidden();
        $this->actingAs($registrar)->post('/assignments', $this->payload([
            'section_id' => $this->sectionA->id, 'subject_id' => $this->subject->id,
        ]))->assertForbidden();
    }
}
