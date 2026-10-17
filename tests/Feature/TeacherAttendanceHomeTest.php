<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Enrollment;
use App\Models\Section;
use App\Models\Subject;
use App\Models\TeachingAssignment;
use App\Models\User;
use App\Notifications\AttendanceCorrected;
use App\Notifications\StudentMarkedAbsent;
use App\Support\SchoolCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

/**
 * The teacher's home knows their class, and attendance stays a HOMEROOM duty: a subject teacher who is not the class
 * teacher never receives attendance authority (however they got to the page), a branch admin or owner may cover any class
 * in their scope, and nobody works outside their branch. Nothing about who may mark, what is validated, who is told or
 * what is audited moved; these tests pin the rules the new screens depend on.
 */
class TeacherAttendanceHomeTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    private User $homeroom;
    private User $subjectTeacher;
    private User $otherTeacher;
    private string $today;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchool();
        $this->today = SchoolCalendar::today()->toDateString();
        $this->homeroom = $this->makeUser('teacher', $this->branchA->id, ['mark-attendance']);
        $this->sectionA->update(['class_teacher_id' => $this->homeroom->id]);
        $this->subjectTeacher = $this->makeUser('teacher', $this->branchA->id, ['mark-attendance']);   // even WITH the permission
        $this->otherTeacher = $this->makeUser('teacher', $this->branchA->id, ['mark-attendance']);
        $sectionC = Section::create(['branch_id' => $this->branchA->id, 'grade_id' => $this->grade->id, 'academic_year_id' => $this->year->id, 'name' => 'C', 'class_teacher_id' => $this->otherTeacher->id]);
        $this->makeStudent($this->branchA, $sectionC, 'Cee', 'STU-C1');
        $subject = Subject::create(['school_id' => $this->school->id, 'name' => 'Maths', 'code' => 'MTH']);
        TeachingAssignment::create(['teacher_id' => $this->subjectTeacher->id, 'section_id' => $this->sectionA->id, 'subject_id' => $subject->id]);
    }

    private function mark(User $by, string $status, $section = null, $student = null)
    {
        $enrollment = Enrollment::where('student_id', ($student ?? $this->studentA)->id)->firstOrFail();

        return $this->actingAs($by)->post(route('attendance.store', $section ?? $this->sectionA), ['date' => $this->today, 'records' => [['enrollment_id' => $enrollment->id, 'status' => $status]]]);
    }

    private function home(User $as): array
    {
        return json_decode(json_encode($this->props($this->actingAs($as)->get('/dashboard')->assertOk())), true)['stats'];
    }

    // ================================================================== the homeroom teacher

    public function test_the_homeroom_teacher_home_carries_their_class_split_into_grade_and_section_with_the_student_count(): void
    {
        $room = $this->home($this->homeroom)['homerooms'][0];

        $this->assertSame($this->sectionA->id, $room['id']);
        $this->assertSame('G1', $room['grade']);
        $this->assertSame('A', $room['section_name']);
        $this->assertSame(1, $room['roster_count']);
        $this->assertSame(0, $room['marked_count']);
        $this->assertSame('none', $room['attendance_status']);
    }

    public function test_the_home_progress_follows_what_is_saved_today_even_when_the_date_is_stored_with_a_time(): void
    {
        $this->mark($this->homeroom, 'present')->assertSessionHasNoErrors();

        $room = $this->home($this->homeroom)['homerooms'][0];
        $this->assertSame(1, $room['marked_count']);
        $this->assertSame('complete', $room['attendance_status']);
    }

    public function test_a_teacher_with_one_class_goes_straight_to_it_and_never_sees_a_choice(): void
    {
        $this->actingAs($this->homeroom)->get(route('attendance.index'))
            ->assertRedirect(route('attendance.show', ['section' => $this->sectionA->id, 'date' => $this->today]));
    }

    public function test_the_attendance_screen_says_it_is_that_teachers_only_class(): void
    {
        $page = json_decode(json_encode($this->props($this->actingAs($this->homeroom)->get(route('attendance.show', $this->sectionA))->assertOk())), true);

        $this->assertSame('G1', $page['section']['grade']);
        $this->assertSame('A', $page['section']['name']);
        $this->assertTrue($page['singleClass']);
        $this->assertSame(1, $page['classCount']);
        $this->assertSame($this->today, $page['date']);
    }

    public function test_a_class_teacher_whose_class_has_no_students_yet_sees_the_explanation_not_a_dead_redirect(): void
    {
        $empty = $this->makeUser('teacher', $this->branchA->id, ['mark-attendance']);
        Section::create(['branch_id' => $this->branchA->id, 'grade_id' => $this->grade->id, 'academic_year_id' => $this->year->id, 'name' => 'Empty', 'class_teacher_id' => $empty->id]);

        $page = $this->props($this->actingAs($empty)->get(route('attendance.index'))->assertOk());
        $this->assertSame('empty', $page['sections'][0]['attendance_status']);
    }

    // ================================================================== a subject teacher who is not the homeroom teacher

    public function test_a_subject_teacher_has_no_class_on_their_home_and_is_told_whose_attendance_it_is(): void
    {
        $stats = $this->home($this->subjectTeacher);

        $this->assertSame([], $stats['homerooms']);
        $this->assertSame(['G1 A'], $stats['taught_only']);
    }

    public function test_a_subject_teacher_never_receives_attendance_authority_even_holding_the_permission(): void
    {
        $this->actingAs($this->subjectTeacher)->get(route('attendance.show', $this->sectionA))->assertForbidden();
        $this->mark($this->subjectTeacher, 'absent')->assertForbidden();
        $this->actingAs($this->subjectTeacher)->get(route('attendance.export', $this->sectionA))->assertForbidden();

        $this->assertSame(0, AttendanceRecord::count(), 'nothing was recorded, nobody was told');
        $index = $this->props($this->actingAs($this->subjectTeacher)->get(route('attendance.index'))->assertOk());
        $this->assertSame([], $index['sections'], 'no redirect and no class to choose');
        $this->assertSame(['G1 A'], collect($index['taughtOnly'])->all());
    }

    public function test_the_explanation_is_the_existing_rule_worded_for_the_teacher(): void
    {
        $this->actingAs($this->subjectTeacher)->get(route('attendance.show', $this->sectionA))
            ->assertForbidden()->assertSee('class (homeroom) teacher', false);
    }

    // ================================================================== a teacher who is not this class's teacher at all

    public function test_an_unrelated_teacher_cannot_open_or_mark_another_class(): void
    {
        $this->actingAs($this->otherTeacher)->get(route('attendance.show', $this->sectionA))->assertForbidden();
        $this->mark($this->otherTeacher, 'present')->assertForbidden();
        $this->assertSame(0, AttendanceRecord::count());
    }

    public function test_a_teacher_without_the_permission_gets_nothing_at_all(): void
    {
        $none = $this->makeUser('teacher', $this->branchA->id, []);
        $this->sectionA->update(['class_teacher_id' => $none->id]);

        $this->actingAs($none)->get(route('attendance.index'))->assertForbidden();
        $this->actingAs($none)->get(route('attendance.show', $this->sectionA))->assertForbidden();
        $this->mark($none, 'present')->assertForbidden();
    }

    // ================================================================== branch admin and owner: coverage

    public function test_a_branch_admin_sees_every_class_of_their_branch_as_a_list_and_can_cover_any(): void
    {
        $admin = $this->makeUser('branch_admin', $this->branchA->id, ['mark-attendance']);

        $index = $this->props($this->actingAs($admin)->get(route('attendance.index'))->assertOk());
        $ids = collect($index['sections'])->pluck('id')->all();
        $this->assertContains($this->sectionA->id, $ids);
        $this->assertNotContains($this->sectionB->id, $ids, 'never another branch');

        $page = $this->props($this->actingAs($admin)->get(route('attendance.show', $this->sectionA))->assertOk());
        $this->assertFalse($page['singleClass']);

        $this->mark($admin, 'present')->assertSessionHasNoErrors();
        $this->assertSame($admin->id, AttendanceRecord::firstOrFail()->marked_by, 'the record says who covered');
    }

    public function test_a_branch_admin_cannot_mark_another_branch(): void
    {
        $admin = $this->makeUser('branch_admin', $this->branchA->id, ['mark-attendance']);

        $this->actingAs($admin)->get(route('attendance.show', $this->sectionB))->assertForbidden();
        $this->mark($admin, 'present', $this->sectionB, $this->studentB)->assertForbidden();
    }

    public function test_the_owner_can_cover_any_class_in_any_branch(): void
    {
        $this->actingAs($this->owner)->get(route('attendance.show', $this->sectionB))->assertOk();
        $this->mark($this->owner, 'late', $this->sectionB, $this->studentB)->assertSessionHasNoErrors();

        $this->assertSame($this->owner->id, AttendanceRecord::firstOrFail()->marked_by);
        $index = $this->props($this->actingAs($this->owner)->get(route('attendance.index'))->assertOk());
        $this->assertGreaterThanOrEqual(3, count($index['sections']));
    }

    // ================================================================== the class teacher is away

    public function test_when_the_class_teacher_is_away_an_administrator_marks_and_the_teacher_sees_who_covered_and_can_correct(): void
    {
        $parent = $this->makeParent($this->studentA);
        $admin = $this->makeUser('branch_admin', $this->branchA->id, ['mark-attendance']);

        $this->mark($admin, 'absent')->assertSessionHasNoErrors();
        $this->assertSame(1, $parent->notifications()->where('type', StudentMarkedAbsent::class)->count(), 'parents are told exactly as before');

        $page = $this->props($this->actingAs($this->homeroom)->get(route('attendance.show', $this->sectionA)));
        $this->assertSame($admin->name, $page['last_saved_by']);
        $this->assertSame($admin->name, $page['roster'][0]['marked_by']);

        // back at school the class teacher corrects it: the correction rules are unchanged
        $this->mark($this->homeroom, 'present')->assertSessionHasNoErrors();
        $this->assertSame(1, $parent->notifications()->where('type', AttendanceCorrected::class)->count());
        $this->assertSame($this->homeroom->id, AttendanceRecord::firstOrFail()->marked_by);
    }
}
