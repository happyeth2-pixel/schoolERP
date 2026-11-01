<?php

namespace Tests\Feature;

use App\Models\OptionalService;
use App\Models\Period;
use App\Models\Room;
use App\Models\Subject;
use App\Models\Term;
use App\Models\TimetableSlot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

/**
 * Period type (teaching/break/lunch) and the after-school-programme schedule it unlocks on OptionalService:
 * a break/lunch period can never host a lesson or a programme, and a period already in use cannot be
 * demoted out from under what is scheduled in it. Answers an AskUserQuestion from this phase ("a real type,
 * not just a name" / "track real per-student signup").
 */
class PeriodTypesAndAfterSchoolTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    private Period $teachingPeriod;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchool();
        $this->teachingPeriod = Period::create(['school_id' => $this->school->id, 'period_number' => 1, 'type' => 'teaching', 'start_time' => '08:00', 'end_time' => '08:40']);
    }

    // ------------------------------------------------------------------ Period type

    public function test_a_period_can_be_created_with_a_break_or_lunch_type(): void
    {
        $hr = $this->makeUser('branch_admin', $this->branchA->id, ['timetable-manage']);

        $this->actingAs($hr)->post('/periods', ['period_number' => 2, 'type' => 'break', 'start_time' => '08:40', 'end_time' => '08:50'])
            ->assertSessionHasNoErrors();

        $this->assertSame('break', Period::where('period_number', 2)->value('type'));
    }

    public function test_an_invalid_period_type_is_rejected(): void
    {
        $hr = $this->makeUser('branch_admin', $this->branchA->id, ['timetable-manage']);

        $this->actingAs($hr)->post('/periods', ['period_number' => 2, 'type' => 'snack', 'start_time' => '08:40', 'end_time' => '08:50'])
            ->assertSessionHasErrors('type');

        $this->assertSame(0, Period::where('period_number', 2)->count());
    }

    public function test_a_period_already_used_for_teaching_cannot_be_demoted_to_break(): void
    {
        $hr = $this->makeUser('branch_admin', $this->branchA->id, ['timetable-manage']);
        $teacher = $this->makeUser('teacher', $this->branchA->id, []);
        $term = Term::create(['academic_year_id' => $this->year->id, 'name' => 'Term 1', 'start_date' => '2026-09-01', 'end_date' => '2027-01-31']);
        $subject = Subject::create(['school_id' => $this->school->id, 'name' => 'Mathematics']);
        TimetableSlot::create([
            'school_id' => $this->school->id, 'branch_id' => $this->branchA->id, 'term_id' => $term->id,
            'section_id' => $this->sectionA->id, 'subject_id' => $subject->id, 'teacher_id' => $teacher->id,
            'period_id' => $this->teachingPeriod->id, 'day_of_week' => 1, 'created_by' => $hr->id,
        ]);

        $this->actingAs($hr)->put("/periods/{$this->teachingPeriod->id}", ['period_number' => 1, 'type' => 'break', 'start_time' => '08:00', 'end_time' => '08:40'])
            ->assertSessionHasErrors('type');

        $this->assertSame('teaching', $this->teachingPeriod->fresh()->type);
    }

    public function test_a_period_used_by_an_after_school_programme_cannot_be_demoted(): void
    {
        $hr = $this->makeUser('branch_admin', $this->branchA->id, ['timetable-manage']);
        OptionalService::create([
            'branch_id' => $this->branchA->id, 'academic_year_id' => $this->year->id, 'name' => 'Chess Club',
            'category' => 'asp', 'amount' => 0, 'frequency' => 'termly', 'period_id' => $this->teachingPeriod->id,
        ]);

        $this->actingAs($hr)->put("/periods/{$this->teachingPeriod->id}", ['period_number' => 1, 'type' => 'lunch', 'start_time' => '08:00', 'end_time' => '08:40'])
            ->assertSessionHasErrors('type');

        $this->assertSame('teaching', $this->teachingPeriod->fresh()->type);
    }

    public function test_an_unused_period_can_be_demoted_freely(): void
    {
        $hr = $this->makeUser('branch_admin', $this->branchA->id, ['timetable-manage']);

        $this->actingAs($hr)->put("/periods/{$this->teachingPeriod->id}", ['period_number' => 1, 'type' => 'break', 'start_time' => '08:00', 'end_time' => '08:40'])
            ->assertSessionHasNoErrors();

        $this->assertSame('break', $this->teachingPeriod->fresh()->type);
    }

    public function test_a_break_period_cannot_host_a_timetable_slot(): void
    {
        $breakPeriod = Period::create(['school_id' => $this->school->id, 'period_number' => 2, 'type' => 'break', 'start_time' => '08:40', 'end_time' => '08:50']);
        $hr = $this->makeUser('branch_admin', $this->branchA->id, ['timetable-manage']);
        $teacher = $this->makeUser('teacher', $this->branchA->id, []);
        $term = Term::create(['academic_year_id' => $this->year->id, 'name' => 'Term 1', 'start_date' => '2026-09-01', 'end_date' => '2027-01-31']);
        $subject = Subject::create(['school_id' => $this->school->id, 'name' => 'Mathematics']);
        $room = Room::create(['branch_id' => $this->branchA->id, 'name' => 'Room 1']);

        $this->actingAs($hr)->post('/timetable', [
            'term_id' => $term->id, 'section_id' => $this->sectionA->id, 'subject_id' => $subject->id,
            'teacher_id' => $teacher->id, 'room_id' => $room->id, 'period_id' => $breakPeriod->id, 'day_of_week' => 1,
        ])->assertSessionHasErrors('period_id');

        $this->assertSame(0, TimetableSlot::count());
    }

    // ------------------------------------------------------------------ After-school programme schedule

    private function aspPayload(array $over = []): array
    {
        return array_merge([
            'branch_id' => $this->branchA->id, 'academic_year_id' => $this->year->id, 'name' => 'Chess Club',
            'category' => 'asp', 'amount' => 300, 'frequency' => 'termly',
        ], $over);
    }

    public function test_an_after_school_programme_can_only_be_scheduled_on_a_teaching_period(): void
    {
        $breakPeriod = Period::create(['school_id' => $this->school->id, 'period_number' => 2, 'type' => 'break', 'start_time' => '08:40', 'end_time' => '08:50']);
        $accountant = $this->makeUser('branch_admin', $this->branchA->id, ['manage-fee-structures']);

        $this->actingAs($accountant)->post(route('optional-services.store'), $this->aspPayload(['period_id' => $breakPeriod->id]))
            ->assertSessionHasErrors('period_id');

        $this->assertSame(0, OptionalService::where('name', 'Chess Club')->count());
    }

    public function test_an_after_school_programme_can_be_scheduled_on_a_teaching_period_with_a_real_teacher(): void
    {
        $accountant = $this->makeUser('branch_admin', $this->branchA->id, ['manage-fee-structures']);
        $teacher = $this->makeUser('teacher', $this->branchA->id, []);

        $this->actingAs($accountant)->post(route('optional-services.store'), $this->aspPayload([
            'period_id' => $this->teachingPeriod->id, 'teacher_id' => $teacher->id, 'days_of_week' => [1, 3],
        ]))->assertSessionHasNoErrors();

        $service = OptionalService::where('name', 'Chess Club')->firstOrFail();
        $this->assertSame($this->teachingPeriod->id, $service->period_id);
        $this->assertSame($teacher->id, $service->teacher_id);
        $this->assertSame([1, 3], $service->days_of_week);
    }

    public function test_an_after_school_programme_teacher_must_actually_hold_the_teacher_role(): void
    {
        $accountant = $this->makeUser('branch_admin', $this->branchA->id, ['manage-fee-structures']);
        $notATeacher = $this->makeUser('branch_admin', $this->branchA->id, []);

        $this->actingAs($accountant)->post(route('optional-services.store'), $this->aspPayload(['teacher_id' => $notATeacher->id]))
            ->assertStatus(422);

        $this->assertSame(0, OptionalService::where('name', 'Chess Club')->count());
    }

    public function test_an_after_school_programme_teacher_must_be_in_the_same_branch(): void
    {
        $accountant = $this->makeUser('owner', null);
        $teacherElsewhere = $this->makeUser('teacher', $this->branchB->id, []);

        $this->actingAs($accountant)->post(route('optional-services.store'), $this->aspPayload(['branch_id' => $this->branchA->id, 'teacher_id' => $teacherElsewhere->id]))
            ->assertStatus(422);

        $this->assertSame(0, OptionalService::where('name', 'Chess Club')->count());
    }

    public function test_a_teacher_sees_only_their_own_programmes_and_only_their_enrolled_students(): void
    {
        $teacherA = $this->makeUser('teacher', $this->branchA->id, []);
        $teacherB = $this->makeUser('teacher', $this->branchA->id, []);
        $chess = OptionalService::create(['branch_id' => $this->branchA->id, 'academic_year_id' => $this->year->id, 'name' => 'Chess Club', 'category' => 'asp', 'amount' => 300, 'frequency' => 'termly', 'period_id' => $this->teachingPeriod->id, 'teacher_id' => $teacherA->id, 'days_of_week' => [1, 3]]);
        $art = OptionalService::create(['branch_id' => $this->branchA->id, 'academic_year_id' => $this->year->id, 'name' => 'Art Club', 'category' => 'asp', 'amount' => 300, 'frequency' => 'termly', 'period_id' => $this->teachingPeriod->id, 'teacher_id' => $teacherB->id]);
        $this->studentA->optionalServices()->attach($chess->id, ['enrolled_on' => '2026-09-01']);
        $this->studentB->optionalServices()->attach($art->id, ['enrolled_on' => '2026-09-01']);

        $response = $this->actingAs($teacherA)->get(route('optional-services.mine'));

        $response->assertInertia(fn ($page) => $page->has('programs', 1)
            ->where('programs.0.name', 'Chess Club')
            ->where('programs.0.days_of_week', [1, 3])
            ->has('programs.0.students', 1)
            ->where('programs.0.students.0.name', $this->studentA->fullName()));
    }
}
