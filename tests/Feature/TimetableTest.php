<?php

namespace Tests\Feature;

use App\Models\Period;
use App\Models\Room;
use App\Models\Subject;
use App\Models\Term;
use App\Models\TimetableSlot;
use App\Models\User;
use App\Notifications\TimetableChanged;
use App\Support\NotificationPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

/**
 * Academic expansion, Phase 1: Timetable. Confirmed phasing via AskUserQuestion -- Timetable first since
 * Period Attendance (Phase 2) depends on it. The three conflict rules are the brief's own words verbatim:
 * a teacher/room/section cannot be double-booked at the same (term, day, period). An authorized
 * `timetable-override` holder can save through a conflict anyway, with a required typed reason, audited.
 */
class TimetableTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    private Term $term;
    private Subject $subject;
    private Period $period;
    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchool();
        $this->term = Term::create(['academic_year_id' => $this->year->id, 'name' => 'Term 1', 'start_date' => '2026-09-01', 'end_date' => '2027-01-31']);
        $this->subject = Subject::create(['school_id' => $this->school->id, 'name' => 'Mathematics']);
        $this->period = Period::create(['school_id' => $this->school->id, 'period_number' => 1, 'start_time' => '08:00', 'end_time' => '08:40']);
        $this->room = Room::create(['branch_id' => $this->branchA->id, 'name' => 'Room 1']);
    }

    /** For real HTTP requests through the controller -- school_id/branch_id are derived server-side, never
     * submitted by the client. */
    private function payload(array $over = []): array
    {
        return array_merge([
            'term_id' => $this->term->id,
            'section_id' => $this->sectionA->id,
            'subject_id' => $this->subject->id,
            'teacher_id' => null, // set per test
            'room_id' => $this->room->id,
            'period_id' => $this->period->id,
            'day_of_week' => 1,
        ], $over);
    }

    /** For creating a slot directly via the model (bypassing the controller) to set up a pre-existing
     * conflict -- unlike payload(), this includes school_id/branch_id since nothing derives them here. */
    private function directPayload(array $over = []): array
    {
        return array_merge($this->payload(), ['school_id' => $this->school->id, 'branch_id' => $this->sectionA->branch_id], $over);
    }

    public function test_creating_a_slot_succeeds_with_no_conflicts(): void
    {
        $hr = $this->makeUser('branch_admin', $this->branchA->id, ['timetable-manage']);
        $teacher = $this->makeUser('teacher', $this->branchA->id, []);

        $this->actingAs($hr)->post('/timetable', $this->payload(['teacher_id' => $teacher->id]))->assertSessionHasNoErrors();

        $slot = TimetableSlot::firstOrFail();
        $this->assertSame($this->sectionA->branch_id, $slot->branch_id);
        $this->assertNull($slot->override_reason);
    }

    public function test_a_teacher_double_booked_at_the_same_time_is_refused(): void
    {
        $hr = $this->makeUser('branch_admin', $this->branchA->id, ['timetable-manage']);
        $teacher = $this->makeUser('teacher', $this->branchA->id, []);
        TimetableSlot::create($this->directPayload(['teacher_id' => $teacher->id, 'created_by' => $hr->id]));

        $otherSection = \App\Models\Section::create(['branch_id' => $this->branchA->id, 'grade_id' => $this->grade->id, 'academic_year_id' => $this->year->id, 'name' => 'C']);
        $this->actingAs($hr)->post('/timetable', $this->payload(['teacher_id' => $teacher->id, 'section_id' => $otherSection->id]))
            ->assertSessionHasErrors('teacher_id');

        $this->assertSame(1, TimetableSlot::count());
    }

    public function test_a_room_double_booked_at_the_same_time_is_refused(): void
    {
        $hr = $this->makeUser('branch_admin', $this->branchA->id, ['timetable-manage']);
        $teacherA = $this->makeUser('teacher', $this->branchA->id, []);
        $teacherB = $this->makeUser('teacher', $this->branchA->id, []);
        TimetableSlot::create($this->directPayload(['teacher_id' => $teacherA->id, 'created_by' => $hr->id]));

        $otherSection = \App\Models\Section::create(['branch_id' => $this->branchA->id, 'grade_id' => $this->grade->id, 'academic_year_id' => $this->year->id, 'name' => 'C']);
        $this->actingAs($hr)->post('/timetable', $this->payload(['teacher_id' => $teacherB->id, 'section_id' => $otherSection->id]))
            ->assertSessionHasErrors('room_id');

        $this->assertSame(1, TimetableSlot::count());
    }

    public function test_a_section_cannot_have_two_subjects_at_the_same_time(): void
    {
        $hr = $this->makeUser('branch_admin', $this->branchA->id, ['timetable-manage']);
        $teacherA = $this->makeUser('teacher', $this->branchA->id, []);
        $teacherB = $this->makeUser('teacher', $this->branchA->id, []);
        TimetableSlot::create($this->directPayload(['teacher_id' => $teacherA->id, 'created_by' => $hr->id]));

        $otherSubject = Subject::create(['school_id' => $this->school->id, 'name' => 'English']);
        $otherRoom = Room::create(['branch_id' => $this->branchA->id, 'name' => 'Room 2']);
        $this->actingAs($hr)->post('/timetable', $this->payload(['teacher_id' => $teacherB->id, 'subject_id' => $otherSubject->id, 'room_id' => $otherRoom->id]))
            ->assertSessionHasErrors('section_id');

        $this->assertSame(1, TimetableSlot::count());
    }

    public function test_a_slot_with_no_room_skips_the_room_conflict_check(): void
    {
        $hr = $this->makeUser('branch_admin', $this->branchA->id, ['timetable-manage']);
        $teacherA = $this->makeUser('teacher', $this->branchA->id, []);
        $teacherB = $this->makeUser('teacher', $this->branchA->id, []);
        TimetableSlot::create($this->directPayload(['teacher_id' => $teacherA->id, 'room_id' => null, 'created_by' => $hr->id]));

        $otherSection = \App\Models\Section::create(['branch_id' => $this->branchA->id, 'grade_id' => $this->grade->id, 'academic_year_id' => $this->year->id, 'name' => 'C']);
        $this->actingAs($hr)->post('/timetable', $this->payload(['teacher_id' => $teacherB->id, 'section_id' => $otherSection->id, 'room_id' => null]))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, TimetableSlot::count());
    }

    public function test_an_authorized_override_saves_despite_a_conflict_and_is_audited(): void
    {
        $hr = $this->makeUser('branch_admin', $this->branchA->id, ['timetable-manage', 'timetable-override']);
        $teacher = $this->makeUser('teacher', $this->branchA->id, []);
        TimetableSlot::create($this->directPayload(['teacher_id' => $teacher->id, 'created_by' => $hr->id]));

        $otherSection = \App\Models\Section::create(['branch_id' => $this->branchA->id, 'grade_id' => $this->grade->id, 'academic_year_id' => $this->year->id, 'name' => 'C']);
        $this->actingAs($hr)->post('/timetable', $this->payload([
            'teacher_id' => $teacher->id, 'section_id' => $otherSection->id,
            'override_confirmed' => true, 'override_reason' => 'Covering for a sick colleague this week.',
        ]))->assertSessionHasNoErrors();

        $this->assertSame(2, TimetableSlot::count());
        $overridden = TimetableSlot::where('section_id', $otherSection->id)->firstOrFail();
        $this->assertSame('Covering for a sick colleague this week.', $overridden->override_reason);
        $this->assertSame(1, \App\Models\AuditLog::where('action', 'timetable_slot.conflict_overridden')->count());
    }

    public function test_someone_without_override_permission_cannot_override_even_with_a_reason(): void
    {
        $hr = $this->makeUser('branch_admin', $this->branchA->id, ['timetable-manage']); // no timetable-override
        $teacher = $this->makeUser('teacher', $this->branchA->id, []);
        TimetableSlot::create($this->directPayload(['teacher_id' => $teacher->id, 'created_by' => $hr->id]));

        $otherSection = \App\Models\Section::create(['branch_id' => $this->branchA->id, 'grade_id' => $this->grade->id, 'academic_year_id' => $this->year->id, 'name' => 'C']);
        $this->actingAs($hr)->post('/timetable', $this->payload([
            'teacher_id' => $teacher->id, 'section_id' => $otherSection->id,
            'override_confirmed' => true, 'override_reason' => 'Trying to bypass anyway.',
        ]))->assertSessionHasErrors('teacher_id');

        $this->assertSame(1, TimetableSlot::count());
    }

    public function test_updating_a_slot_excludes_itself_from_its_own_conflict_check(): void
    {
        $hr = $this->makeUser('branch_admin', $this->branchA->id, ['timetable-manage']);
        $teacher = $this->makeUser('teacher', $this->branchA->id, []);
        $slot = TimetableSlot::create($this->directPayload(['teacher_id' => $teacher->id, 'created_by' => $hr->id]));

        $this->actingAs($hr)->put("/timetable/{$slot->id}", $this->payload(['teacher_id' => $teacher->id, 'day_of_week' => 2]))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, $slot->fresh()->day_of_week);
    }

    public function test_branch_scoping_refuses_a_section_outside_the_actors_branch(): void
    {
        $hr = $this->makeUser('branch_admin', $this->branchA->id, ['timetable-manage']);
        $teacher = $this->makeUser('teacher', $this->branchA->id, []);

        $this->actingAs($hr)->post('/timetable', $this->payload(['teacher_id' => $teacher->id, 'section_id' => $this->sectionB->id, 'room_id' => null]))
            ->assertForbidden();
    }

    public function test_a_room_from_a_different_branch_than_the_section_is_refused(): void
    {
        $hr = $this->makeUser('owner', null); // org-wide, so branch scope alone wouldn't catch this
        $teacher = $this->makeUser('teacher', $this->branchA->id, []);
        $roomInOtherBranch = Room::create(['branch_id' => $this->branchB->id, 'name' => 'East Room']);

        $this->actingAs($hr)->post('/timetable', $this->payload(['teacher_id' => $teacher->id, 'room_id' => $roomInOtherBranch->id]))
            ->assertSessionHasErrors('room_id');

        $this->assertSame(0, TimetableSlot::count());
    }

    public function test_someone_without_any_timetable_permission_is_refused(): void
    {
        $bystander = $this->makeUser('teacher', $this->branchA->id, []);

        $this->actingAs($bystander)->get('/timetable')->assertForbidden();
        $this->actingAs($bystander)->post('/timetable', $this->payload(['teacher_id' => $bystander->id]))->assertForbidden();
    }

    public function test_a_teacher_sees_only_their_own_slots_on_my_timetable(): void
    {
        $hr = $this->makeUser('branch_admin', $this->branchA->id, ['timetable-manage']);
        $teacherA = $this->makeUser('teacher', $this->branchA->id, []);
        $teacherB = $this->makeUser('teacher', $this->branchA->id, []);
        TimetableSlot::create($this->directPayload(['teacher_id' => $teacherA->id, 'created_by' => $hr->id]));
        $otherRoom = Room::create(['branch_id' => $this->branchA->id, 'name' => 'Room 2']);
        TimetableSlot::create($this->directPayload(['teacher_id' => $teacherB->id, 'room_id' => $otherRoom->id, 'day_of_week' => 2, 'created_by' => $hr->id]));

        $response = $this->actingAs($teacherA)->get('/my-timetable');
        $response->assertInertia(fn ($page) => $page->has('slots', 1));
    }

    public function test_a_period_cannot_be_removed_while_in_use(): void
    {
        $hr = $this->makeUser('branch_admin', $this->branchA->id, ['timetable-manage']);
        $teacher = $this->makeUser('teacher', $this->branchA->id, []);
        TimetableSlot::create($this->directPayload(['teacher_id' => $teacher->id, 'created_by' => $hr->id]));

        $this->actingAs($hr)->delete("/periods/{$this->period->id}")->assertSessionHasErrors();
        $this->assertNotNull(Period::find($this->period->id));
    }

    public function test_a_room_cannot_be_removed_while_in_use(): void
    {
        $hr = $this->makeUser('branch_admin', $this->branchA->id, ['timetable-manage']);
        $teacher = $this->makeUser('teacher', $this->branchA->id, []);
        TimetableSlot::create($this->directPayload(['teacher_id' => $teacher->id, 'created_by' => $hr->id]));

        $this->actingAs($hr)->delete("/rooms/{$this->room->id}")->assertSessionHasErrors();
        $this->assertNotNull(Room::find($this->room->id));
    }

    public function test_the_timetable_changed_notification_links_to_my_timetable(): void
    {
        $hr = $this->makeUser('branch_admin', $this->branchA->id, ['timetable-manage']);
        $teacher = $this->makeUser('teacher', $this->branchA->id, []);
        $slot = TimetableSlot::create($this->directPayload(['teacher_id' => $teacher->id, 'created_by' => $hr->id]));

        $teacher->notify(new TimetableChanged($slot));
        $presented = NotificationPresenter::present($teacher->notifications()->first());

        $this->assertSame(route('timetable.mine'), $presented['action_url']);
        $this->actingAs($teacher)->get($presented['action_url'])->assertOk();
    }

    public function test_finalizing_a_slot_notifies_the_teacher(): void
    {
        Notification::fake();
        $hr = $this->makeUser('branch_admin', $this->branchA->id, ['timetable-manage']);
        $teacher = $this->makeUser('teacher', $this->branchA->id, []);

        $this->actingAs($hr)->post('/timetable', $this->payload(['teacher_id' => $teacher->id]));

        Notification::assertSentTo($teacher, TimetableChanged::class);
    }
}
