<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Period;
use App\Models\PeriodAttendanceRecord;
use App\Models\Subject;
use App\Models\Term;
use App\Models\TimetableSlot;
use App\Notifications\StudentMarkedAbsent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

/**
 * Academic expansion, Phase 2: Period/Subject Attendance. Deliberately separate from the EXISTING daily
 * `AttendanceRecord` (one row per student per day, homeroom-only) -- this is one row per student per
 * `TimetableSlot` occurrence per date. The brief's own words: "restricted to the assigned teacher or
 * admins," reusing the EXISTING `mark-attendance` permission rather than adding a new one.
 */
class PeriodAttendanceTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    private Term $term;

    private Subject $subject;

    private Period $period;

    private TimetableSlot $slot;

    private \App\Models\User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchool();
        $this->term = Term::create(['academic_year_id' => $this->year->id, 'name' => 'Term 1', 'start_date' => '2026-09-01', 'end_date' => '2027-01-31']);
        $this->subject = Subject::create(['school_id' => $this->school->id, 'name' => 'Mathematics']);
        $this->period = Period::create(['school_id' => $this->school->id, 'period_number' => 1, 'start_time' => '08:00', 'end_time' => '08:40']);
        $this->teacher = $this->makeUser('teacher', $this->branchA->id, ['mark-attendance']);

        // Monday 2026-09-07 is a real Monday -- day_of_week 1 matches it.
        $this->slot = TimetableSlot::create([
            'school_id' => $this->school->id, 'branch_id' => $this->branchA->id, 'term_id' => $this->term->id,
            'section_id' => $this->sectionA->id, 'subject_id' => $this->subject->id, 'teacher_id' => $this->teacher->id,
            'room_id' => null, 'period_id' => $this->period->id, 'day_of_week' => 1, 'created_by' => $this->teacher->id,
        ]);
    }

    private function enrollStudents(int $n): array
    {
        $ids = [];
        for ($i = 0; $i < $n; $i++) {
            $student = $this->makeStudent($this->branchA, $this->sectionA, "Student{$i}", "PA-{$i}");
            $ids[] = $student->enrollments()->first()->id;
        }

        return $ids;
    }

    public function test_the_assigned_teacher_can_mark_their_own_slot(): void
    {
        [$e1, $e2] = $this->enrollStudents(2);

        $this->actingAs($this->teacher)->post("/period-attendance/{$this->slot->id}", [
            'date' => '2026-09-07',
            'records' => [
                ['enrollment_id' => $e1, 'status' => 'present'],
                ['enrollment_id' => $e2, 'status' => 'absent'],
            ],
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, PeriodAttendanceRecord::count());
        $this->assertSame('absent', PeriodAttendanceRecord::where('enrollment_id', $e2)->first()->status);
    }

    public function test_a_different_teacher_cannot_mark_someone_elses_slot(): void
    {
        $otherTeacher = $this->makeUser('teacher', $this->branchA->id, ['mark-attendance']);
        [$e1] = $this->enrollStudents(1);

        $this->actingAs($otherTeacher)->post("/period-attendance/{$this->slot->id}", [
            'date' => '2026-09-07',
            'records' => [['enrollment_id' => $e1, 'status' => 'present']],
        ])->assertForbidden();

        $this->assertSame(0, PeriodAttendanceRecord::count());
    }

    public function test_a_teacher_without_mark_attendance_is_refused_even_for_their_own_slot(): void
    {
        $noPerm = $this->makeUser('teacher', $this->branchA->id, []);
        $slot = TimetableSlot::create([
            'school_id' => $this->school->id, 'branch_id' => $this->branchA->id, 'term_id' => $this->term->id,
            'section_id' => $this->sectionA->id, 'subject_id' => $this->subject->id, 'teacher_id' => $noPerm->id,
            'room_id' => null, 'period_id' => $this->period->id, 'day_of_week' => 2, 'created_by' => $this->teacher->id,
        ]);

        $this->actingAs($noPerm)->get("/period-attendance/{$slot->id}?date=2026-09-08")->assertForbidden();
    }

    public function test_a_branch_admin_can_cover_any_teachers_slot(): void
    {
        $admin = $this->makeUser('branch_admin', $this->branchA->id, ['mark-attendance']);
        [$e1] = $this->enrollStudents(1);

        $this->actingAs($admin)->post("/period-attendance/{$this->slot->id}", [
            'date' => '2026-09-07',
            'records' => [['enrollment_id' => $e1, 'status' => 'late']],
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, PeriodAttendanceRecord::count());
    }

    public function test_marking_on_a_date_that_isnt_this_slots_weekday_is_refused(): void
    {
        [$e1] = $this->enrollStudents(1);

        // 2026-09-08 is a Tuesday; the slot is Monday (day_of_week 1).
        $this->actingAs($this->teacher)->post("/period-attendance/{$this->slot->id}", [
            'date' => '2026-09-08',
            'records' => [['enrollment_id' => $e1, 'status' => 'present']],
        ])->assertNotFound();

        $this->assertSame(0, PeriodAttendanceRecord::count());
    }

    public function test_unmarked_students_stay_unmarked(): void
    {
        [$e1, $e2] = $this->enrollStudents(2);

        $this->actingAs($this->teacher)->post("/period-attendance/{$this->slot->id}", [
            'date' => '2026-09-07',
            'records' => [['enrollment_id' => $e1, 'status' => 'present']],
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, PeriodAttendanceRecord::count());
        $this->assertNull(PeriodAttendanceRecord::where('enrollment_id', $e2)->first());
    }

    public function test_marking_again_updates_the_existing_record_not_a_duplicate(): void
    {
        [$e1] = $this->enrollStudents(1);
        $this->actingAs($this->teacher)->post("/period-attendance/{$this->slot->id}", [
            'date' => '2026-09-07', 'records' => [['enrollment_id' => $e1, 'status' => 'present']],
        ]);

        $admin = $this->makeUser('branch_admin', $this->branchA->id, ['mark-attendance']);
        $this->actingAs($admin)->post("/period-attendance/{$this->slot->id}", [
            'date' => '2026-09-07', 'records' => [['enrollment_id' => $e1, 'status' => 'late']],
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, PeriodAttendanceRecord::count());
        $record = PeriodAttendanceRecord::first();
        $this->assertSame('late', $record->status);
        $this->assertSame($admin->id, $record->marked_by);
    }

    public function test_someone_without_mark_attendance_at_all_is_refused(): void
    {
        $accountant = $this->makeUser('accountant', $this->branchA->id, []);

        $this->actingAs($accountant)->get('/period-attendance')->assertForbidden();
        $this->actingAs($accountant)->get("/period-attendance/{$this->slot->id}?date=2026-09-07")->assertForbidden();
    }

    public function test_branch_scoping_refuses_a_slot_outside_the_actors_branch(): void
    {
        $otherBranchAdmin = $this->makeUser('branch_admin', $this->branchB->id, ['mark-attendance']);

        $this->actingAs($otherBranchAdmin)->get("/period-attendance/{$this->slot->id}?date=2026-09-07")->assertForbidden();
    }

    public function test_a_timetable_slot_cannot_be_removed_while_it_has_period_attendance_history(): void
    {
        [$e1] = $this->enrollStudents(1);
        $this->actingAs($this->teacher)->post("/period-attendance/{$this->slot->id}", [
            'date' => '2026-09-07', 'records' => [['enrollment_id' => $e1, 'status' => 'present']],
        ]);

        $admin = $this->makeUser('branch_admin', $this->branchA->id, ['timetable-manage']);
        $this->actingAs($admin)->delete("/timetable/{$this->slot->id}")->assertSessionHasErrors('delete');

        $this->assertSame(1, TimetableSlot::count());
    }

    public function test_creating_a_mark_is_not_audited_but_changing_one_is(): void
    {
        [$e1] = $this->enrollStudents(1);
        $this->actingAs($this->teacher)->post("/period-attendance/{$this->slot->id}", [
            'date' => '2026-09-07', 'records' => [['enrollment_id' => $e1, 'status' => 'present']],
        ]);
        $this->assertSame(0, AuditLog::where('action', 'period_attendance.status_changed')->count());

        $this->actingAs($this->teacher)->post("/period-attendance/{$this->slot->id}", [
            'date' => '2026-09-07', 'records' => [['enrollment_id' => $e1, 'status' => 'absent']],
        ]);
        $this->assertSame(1, AuditLog::where('action', 'period_attendance.status_changed')->count());
    }

    public function test_marking_a_student_absent_in_a_period_never_notifies_a_guardian(): void
    {
        Notification::fake();
        $student = $this->makeStudent($this->branchA, $this->sectionA, 'Lonely', 'PA-LONE');
        $parent = $this->makeParent($student);
        $enrollmentId = $student->enrollments()->first()->id;

        $this->actingAs($this->teacher)->post("/period-attendance/{$this->slot->id}", [
            'date' => '2026-09-07', 'records' => [['enrollment_id' => $enrollmentId, 'status' => 'absent']],
        ])->assertSessionHasNoErrors();

        Notification::assertNothingSentTo($parent);
        Notification::assertNotSentTo($parent, StudentMarkedAbsent::class);
    }

    public function test_index_lists_the_teachers_own_slots_for_the_chosen_day(): void
    {
        $response = $this->actingAs($this->teacher)->get('/period-attendance?date=2026-09-07');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->has('slots', 1)->where('slots.0.id', $this->slot->id));
    }

    public function test_a_branch_admin_sees_no_slots_until_a_teacher_is_picked(): void
    {
        $admin = $this->makeUser('branch_admin', $this->branchA->id, ['mark-attendance']);

        $response = $this->actingAs($admin)->get('/period-attendance?date=2026-09-07');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->has('slots', 0));

        $response = $this->actingAs($admin)->get("/period-attendance?date=2026-09-07&teacher_id={$this->teacher->id}");
        $response->assertInertia(fn ($page) => $page->has('slots', 1));
    }
}
