<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Enrollment;
use App\Models\Student;
use App\Models\User;
use App\Notifications\AttendanceCorrected;
use App\Notifications\StudentMarkedAbsent;
use App\Support\SchoolCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

/**
 * The attendance rules the teacher screen must keep: who may mark which class, that a save may cover only some of
 * the class (the rest stay unmarked, never silently "present"), what parents are told and when, and the
 * validation. The screen itself was redesigned; none of these rules changed.
 */
class AttendanceWorkflowTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    private User $teacher;
    private Student $second;
    private Student $third;
    private User $parent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchool();
        $this->teacher = $this->makeUser('teacher', $this->branchA->id, ['mark-attendance']);
        $this->sectionA->update(['class_teacher_id' => $this->teacher->id]);
        $this->second = $this->makeStudent($this->branchA, $this->sectionA, 'Second', 'STU-A2');
        $this->third = $this->makeStudent($this->branchA, $this->sectionA, 'Third', 'STU-A3');
        $this->parent = $this->makeParent($this->studentA);
    }

    private function enrollmentOf(int $studentId): int
    {
        return Enrollment::where('student_id', $studentId)->value('id');
    }

    private function save(User $by, array $marks, string $date = '2026-09-28', $section = null)
    {
        $records = [];
        foreach ($marks as $studentId => $status) {
            $records[] = ['enrollment_id' => $this->enrollmentOf($studentId), 'status' => $status];
        }

        return $this->actingAs($by)->post(route('attendance.store', $section ?? $this->sectionA), ['date' => $date, 'records' => $records]);
    }

    // ------------------------------------------------------------------ what the screen is given

    public function test_nobody_is_marked_by_default(): void
    {
        $page = $this->props($this->actingAs($this->teacher)->get(route('attendance.show', $this->sectionA).'?date=2026-09-28')->assertOk());

        $this->assertCount(3, $page['roster']);
        $this->assertSame([null, null, null], collect($page['roster'])->pluck('status')->all(), 'the server never invents a present mark');
        $this->assertArrayHasKey('today', $page);
    }

    public function test_the_screen_knows_who_saved_a_mark_and_how_many_parents_are_linked_but_never_who_they_are(): void
    {
        $this->save($this->teacher, [$this->studentA->id => 'absent'])->assertSessionHasNoErrors();
        $roster = $this->props($this->actingAs($this->teacher)->get(route('attendance.show', $this->sectionA).'?date=2026-09-28'))['roster'];
        $row = collect($roster)->firstWhere('student_id_number', 'STU-A');

        $this->assertSame('absent', $row['status']);
        $this->assertSame($this->teacher->name, $row['marked_by']);
        $this->assertSame(1, $row['parent_count']);
        $this->assertStringNotContainsString($this->parent->email, json_encode($roster));
    }

    // ------------------------------------------------------------------ who may mark which class

    public function test_a_teacher_marks_only_their_own_class_a_branch_admin_can_cover_anyone_in_their_branch(): void
    {
        $other = $this->makeUser('teacher', $this->branchA->id, ['mark-attendance']);
        $cover = $this->makeUser('branch_admin', $this->branchA->id, ['mark-attendance']);
        $eastAdmin = $this->makeUser('branch_admin', $this->branchB->id, ['mark-attendance']);
        $noPermission = $this->makeUser('teacher', $this->branchA->id, []);
        $url = route('attendance.show', $this->sectionA);

        $this->actingAs($this->teacher)->get($url)->assertOk();
        $this->actingAs($other)->get($url)->assertForbidden();
        $this->save($other, [$this->studentA->id => 'present'])->assertForbidden();
        $this->actingAs($cover)->get($url)->assertOk();
        $this->save($cover, [$this->studentA->id => 'present'])->assertSessionHasNoErrors();
        $this->actingAs($eastAdmin)->get($url)->assertForbidden();
        $this->save($eastAdmin, [$this->studentA->id => 'present'])->assertForbidden();
        $this->actingAs($noPermission)->get($url)->assertForbidden();
        $this->save($noPermission, [$this->studentA->id => 'present'])->assertForbidden();
        $this->assertSame(1, AttendanceRecord::count(), 'only the covering admin\'s save landed');
    }

    public function test_the_class_list_shows_only_the_teachers_own_classes(): void
    {
        // a teacher with ONE class is taken straight to it (nothing to choose); with two they get a list of just those two
        $this->actingAs($this->teacher)->get(route('attendance.index'))->assertRedirect(route('attendance.show', ['section' => $this->sectionA->id, 'date' => SchoolCalendar::today()->toDateString()]));

        $extra = \App\Models\Section::create(['branch_id' => $this->branchA->id, 'grade_id' => $this->grade->id, 'academic_year_id' => $this->year->id, 'name' => 'Extra', 'class_teacher_id' => $this->teacher->id]);
        $this->makeStudent($this->branchA, $extra, 'Ex', 'STU-EX');
        $sections = $this->props($this->actingAs($this->teacher)->get(route('attendance.index')))['sections'];

        $this->assertEqualsCanonicalizing([$this->sectionA->id, $extra->id], collect($sections)->pluck('id')->all());
        $this->assertNotContains($this->sectionB->id, collect($sections)->pluck('id')->all());
    }

    // ------------------------------------------------------------------ saving

    public function test_a_save_may_cover_part_of_the_class_and_the_rest_stay_unmarked(): void
    {
        $today = SchoolCalendar::today()->toDateString();
        $this->save($this->teacher, [$this->studentA->id => 'present', $this->second->id => 'late'], $today)->assertSessionHasNoErrors();

        $this->assertSame(2, AttendanceRecord::count(), 'nothing is recorded for the student who was not marked');
        $page = $this->props($this->actingAs($this->teacher)->get(route('attendance.show', $this->sectionA)."?date={$today}"));
        $this->assertNull(collect($page['roster'])->firstWhere('student_id_number', 'STU-A3')['status']);

        $covering = $this->makeUser('branch_admin', $this->branchA->id, ['mark-attendance']);
        $card = collect($this->props($this->actingAs($covering)->get(route('attendance.index')))['sections'])->firstWhere('id', $this->sectionA->id);
        $this->assertSame([3, 2, 'partial'], [$card['roster_count'], $card['marked_count'], $card['attendance_status']]);
    }

    public function test_every_status_the_app_supports_is_accepted_and_nothing_else(): void
    {
        foreach (['present', 'absent', 'late', 'excused', 'sick'] as $i => $status) {
            $this->save($this->teacher, [$this->studentA->id => $status], '2026-09-1'.$i)->assertSessionHasNoErrors();
        }
        $this->save($this->teacher, [$this->studentA->id => 'holiday'])->assertSessionHasErrors('records.0.status');
        $this->save($this->teacher, [$this->studentA->id => ''])->assertSessionHasErrors('records.0.status');
    }

    public function test_validation_refuses_an_empty_save_a_missing_date_and_a_student_from_another_class(): void
    {
        $this->actingAs($this->teacher)->post(route('attendance.store', $this->sectionA), ['date' => '2026-09-28', 'records' => []])->assertSessionHasErrors('records');
        $this->actingAs($this->teacher)->post(route('attendance.store', $this->sectionA), ['records' => [['enrollment_id' => $this->enrollmentOf($this->studentA->id), 'status' => 'present']]])->assertSessionHasErrors('date');
        $this->save($this->teacher, [$this->studentB->id => 'present'])->assertStatus(422);   // East branch's student, not in this class

        $this->assertSame(0, AttendanceRecord::count());
    }

    /** §26 hardening: the save loop now runs inside one transaction -- a bad record partway through a
     * multi-student request must roll back everyone in that same request, not leave the earlier ones saved
     * with the rest silently missing. */
    public function test_an_invalid_record_partway_through_a_multi_student_save_rolls_back_the_whole_request(): void
    {
        $records = [
            ['enrollment_id' => $this->enrollmentOf($this->studentA->id), 'status' => 'present'], // valid, same class
            ['enrollment_id' => $this->enrollmentOf($this->studentB->id), 'status' => 'present'], // East branch's student -- not in this class
        ];

        $this->actingAs($this->teacher)
            ->post(route('attendance.store', $this->sectionA), ['date' => '2026-09-28', 'records' => $records])
            ->assertStatus(422);

        $this->assertSame(0, AttendanceRecord::count(), 'the valid first record must not have been left saved once the second one aborted');
    }

    // ------------------------------------------------------------------ what parents are told

    public function test_marking_a_student_absent_tells_their_parents_once(): void
    {
        Notification::fake();

        $this->save($this->teacher, [$this->studentA->id => 'absent']);
        $this->save($this->teacher, [$this->studentA->id => 'absent']);   // saved again unchanged: no second message

        Notification::assertSentToTimes($this->parent, StudentMarkedAbsent::class, 1);
        Notification::assertNotSentTo($this->parent, AttendanceCorrected::class);
    }

    public function test_changing_an_absence_tells_the_parents_it_was_corrected_and_the_mark_is_updated(): void
    {
        $this->save($this->teacher, [$this->studentA->id => 'absent']);
        Notification::fake();

        $cover = $this->makeUser('branch_admin', $this->branchA->id, ['mark-attendance']);
        $this->save($cover, [$this->studentA->id => 'present'])->assertSessionHasNoErrors();

        Notification::assertSentTo($this->parent, AttendanceCorrected::class);
        $record = AttendanceRecord::firstOrFail();
        $this->assertSame('present', $record->status);
        $this->assertSame($cover->id, $record->marked_by, 'the record shows who made the correction');
        $this->assertSame(1, AttendanceRecord::count(), 'a correction updates the day\'s record, never adds a second');
    }

    public function test_changing_to_absent_tells_parents_but_other_corrections_are_silent(): void
    {
        $this->save($this->teacher, [$this->studentA->id => 'present']);
        Notification::fake();

        $this->save($this->teacher, [$this->studentA->id => 'absent']);
        Notification::assertSentToTimes($this->parent, StudentMarkedAbsent::class, 1);

        Notification::fake();
        $this->save($this->teacher, [$this->second->id => 'late']);
        $this->save($this->teacher, [$this->second->id => 'present']);
        Notification::assertNothingSent();
    }

    public function test_the_correction_is_audited(): void
    {
        $this->save($this->teacher, [$this->studentA->id => 'absent']);
        $this->save($this->teacher, [$this->studentA->id => 'present']);

        $this->assertDatabaseHas('audit_logs', ['action' => 'attendance.status_changed']);
    }
}
