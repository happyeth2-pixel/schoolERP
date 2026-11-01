<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\AuditLog;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\Invoice;
use App\Models\Section;
use App\Models\Student;
use App\Models\Term;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

/** Items 35-37: protected year switching, term dates, section capacity. */
class YearsTermsCapacityTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchool();
    }

    // ------------------------------------------------------------------ 35

    public function test_the_database_itself_refuses_a_second_current_year_in_a_school(): void
    {
        $this->expectException(QueryException::class);
        AcademicYear::create(['school_id' => $this->school->id, 'name' => '2027', 'start_date' => '2027-09-01', 'end_date' => '2028-06-30', 'is_current' => true]);
    }

    public function test_the_marker_follows_is_current_and_other_schools_are_independent(): void
    {
        $this->assertSame($this->school->id, $this->year->fresh()->current_school_id);

        $other = \App\Models\School::create(['name' => 'Other', 'code' => 'OTH1']);
        $y = AcademicYear::create(['school_id' => $other->id, 'name' => '2026', 'start_date' => '2026-09-01', 'end_date' => '2027-06-30', 'is_current' => true]);
        $this->assertSame($other->id, $y->current_school_id);

        $this->year->update(['is_current' => false]);
        $this->assertNull($this->year->fresh()->current_school_id);
    }

    public function test_creating_or_editing_a_year_never_switches_the_current_one(): void
    {
        $this->actingAs($this->owner)->post('/academic-years', ['name' => '2027', 'start_date' => '2027-09-01', 'end_date' => '2028-06-30', 'is_current' => true])->assertSessionHasNoErrors();
        $next = AcademicYear::where('name', '2027')->firstOrFail();
        $this->assertFalse($next->is_current, 'a flag in the request is ignored');
        $this->assertTrue($this->year->fresh()->is_current);

        $this->actingAs($this->owner)->patch("/academic-years/{$next->id}", ['name' => '2027 E.C.', 'start_date' => '2027-09-01', 'end_date' => '2028-06-30', 'is_current' => true])->assertSessionHasNoErrors();
        $this->assertFalse($next->fresh()->is_current);
        $this->assertTrue($this->year->fresh()->is_current);
    }

    public function test_a_schools_very_first_year_becomes_current(): void
    {
        $school = \App\Models\School::create(['name' => 'New', 'code' => 'NEW1']);
        $boss = $this->makeUserIn($school);
        $this->actingAs($boss)->post('/academic-years', ['name' => '2026', 'start_date' => '2026-09-01', 'end_date' => '2027-06-30'])->assertSessionHasNoErrors();
        $this->assertTrue(AcademicYear::where('school_id', $school->id)->firstOrFail()->is_current);
    }

    public function test_switching_is_a_protected_audited_action_that_changes_no_history(): void
    {
        $next = AcademicYear::create(['school_id' => $this->school->id, 'name' => '2027', 'start_date' => '2027-09-01', 'end_date' => '2028-06-30', 'is_current' => false]);
        $invoice = Invoice::create(['student_id' => $this->studentA->id, 'academic_year_id' => $this->year->id, 'invoice_number' => 'INV-1', 'total_amount' => 100, 'status' => 'pending', 'frequency' => 'annual', 'period_start' => '2026-09-01']);
        $before = [Enrollment::count(), Invoice::count(), $invoice->fresh()->toArray(), Enrollment::first()->toArray()];
        $go = fn ($user, array $d) => $this->actingAs($user)->post("/academic-years/{$next->id}/make-current", $d);

        // who
        $branchAdmin = $this->makeUser('branch_admin', $this->branchA->id);
        $go($branchAdmin, ['confirm_name' => '2027', 'reason' => 'New year'])->assertForbidden();
        $this->actingAs($this->makeUser('accountant', null))->post("/academic-years/{$next->id}/make-current", ['confirm_name' => '2027', 'reason' => 'x'])->assertForbidden();

        // confirmation and reason are required
        $go($this->owner, ['confirm_name' => '2026', 'reason' => 'New year'])->assertSessionHasErrors('confirm_name');
        $go($this->owner, ['confirm_name' => '2027', 'reason' => ''])->assertSessionHasErrors('reason');
        $this->assertTrue($this->year->fresh()->is_current, 'nothing changed by the refused attempts');

        $go($this->owner, ['confirm_name' => '2027', 'reason' => 'Term starts Monday'])->assertSessionHasNoErrors();
        $this->assertTrue($next->fresh()->is_current);
        $this->assertFalse($this->year->fresh()->is_current);
        $this->assertSame(1, AcademicYear::where('school_id', $this->school->id)->where('is_current', true)->count());

        // history is untouched
        $this->assertSame($before, [Enrollment::count(), Invoice::count(), $invoice->fresh()->toArray(), Enrollment::first()->toArray()]);

        // one audit entry with before/after and the reason
        $log = AuditLog::where('action', 'academic_year.current_changed')->firstOrFail();
        $this->assertSame(['current_year' => '2026'], $log->before);
        $this->assertSame(['current_year' => '2027'], $log->after);
        $this->assertSame('Term starts Monday', $log->note);
        $this->assertSame(0, AuditLog::where('action', 'academic_year.updated')->count(), 'no duplicate per-row entries');

        $go($this->owner, ['confirm_name' => '2027', 'reason' => 'again'])->assertSessionHasErrors('confirm_name');
    }

    public function test_year_dates_must_still_contain_its_terms(): void
    {
        Term::create(['academic_year_id' => $this->year->id, 'name' => 'T1', 'start_date' => '2026-09-01', 'end_date' => '2026-12-20']);
        $this->actingAs($this->owner)->patch("/academic-years/{$this->year->id}", ['name' => '2026', 'start_date' => '2026-10-01', 'end_date' => '2027-06-30'])->assertSessionHasErrors('start_date');
        $this->assertSame('2026-09-01', $this->year->fresh()->start_date->toDateString());
    }

    // ------------------------------------------------------------------ 36

    private function addTerm(string $name, string $start, string $end)
    {
        return $this->actingAs($this->owner)->post("/academic-years/{$this->year->id}/terms", ['name' => $name, 'start_date' => $start, 'end_date' => $end]);
    }

    public function test_terms_must_sit_inside_the_year_and_start_before_they_end(): void
    {
        $this->addTerm('Early', '2026-08-15', '2026-12-01')->assertSessionHasErrors('start_date');
        $this->addTerm('Late', '2027-05-01', '2027-07-15')->assertSessionHasErrors('end_date');
        $this->addTerm('Backwards', '2026-11-01', '2026-10-01')->assertSessionHasErrors('end_date');
        $this->addTerm('Same day', '2026-11-01', '2026-11-01')->assertSessionHasErrors('end_date');
        $this->assertSame(0, Term::count());

        $this->addTerm('Whole year', '2026-09-01', '2027-06-30')->assertSessionHasNoErrors();   // exactly the year's edges is fine
        $this->assertSame(1, Term::count());
    }

    public function test_terms_of_one_year_may_not_overlap_but_may_touch_the_next_days(): void
    {
        $this->addTerm('T1', '2026-09-01', '2026-12-20')->assertSessionHasNoErrors();
        $this->addTerm('overlaps', '2026-12-01', '2027-03-01')->assertSessionHasErrors('start_date');
        $this->addTerm('shares the last day', '2026-12-20', '2027-03-01')->assertSessionHasErrors('start_date');
        $this->addTerm('inside', '2026-10-01', '2026-11-01')->assertSessionHasErrors('start_date');
        $this->addTerm('swallows', '2026-08-31', '2027-01-01')->assertSessionHasErrors();
        $this->addTerm('T2', '2026-12-21', '2027-03-30')->assertSessionHasNoErrors();
        $this->assertSame(2, Term::count());

        // editing a term is checked against the OTHERS, not itself
        $t2 = Term::where('name', 'T2')->first();
        $this->actingAs($this->owner)->patch("/terms/{$t2->id}", ['name' => 'T2', 'start_date' => '2026-12-21', 'end_date' => '2027-04-15'])->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->patch("/terms/{$t2->id}", ['name' => 'T2', 'start_date' => '2026-12-10', 'end_date' => '2027-04-15'])->assertSessionHasErrors('start_date');
        $this->assertSame('2027-04-15', $t2->fresh()->end_date->toDateString());
    }

    public function test_overlap_is_allowed_only_when_the_school_says_so_but_the_year_bounds_still_apply(): void
    {
        $this->school->update(['allow_overlapping_terms' => true]);
        $this->addTerm('T1', '2026-09-01', '2026-12-20')->assertSessionHasNoErrors();
        $this->addTerm('Overlapping', '2026-12-01', '2027-03-01')->assertSessionHasNoErrors();
        $this->addTerm('Outside', '2027-06-01', '2027-08-01')->assertSessionHasErrors('end_date');
    }

    public function test_a_term_can_overlap_a_term_of_a_different_year(): void
    {
        $other = AcademicYear::create(['school_id' => $this->school->id, 'name' => '2027', 'start_date' => '2026-12-01', 'end_date' => '2028-06-30', 'is_current' => false]);
        Term::create(['academic_year_id' => $other->id, 'name' => 'X', 'start_date' => '2026-12-01', 'end_date' => '2027-02-01']);
        $this->addTerm('T1', '2026-09-01', '2026-12-20')->assertSessionHasNoErrors();
    }

    // ------------------------------------------------------------------ 37

    private function fullSection(int $capacity = 1): Section
    {
        $section = Section::create(['branch_id' => $this->branchA->id, 'grade_id' => $this->grade->id, 'academic_year_id' => $this->year->id, 'name' => 'Small', 'capacity' => $capacity]);
        for ($i = 0; $i < $capacity; $i++) {
            $this->makeStudent($this->branchA, $section, "Seat{$i}", "STU-F{$i}");
        }

        return $section;
    }

    private function enrolPayload(Section $section, array $extra = []): array
    {
        return $extra + ['first_name' => 'Newcomer', 'gender' => 'male', 'enrollment_date' => '2026-09-20', 'section_id' => $section->id, 'guardian_mode' => 'none', 'emergency_contact' => '0911223344'];
    }

    public function test_enrolling_into_a_full_section_is_refused_and_creates_nothing(): void
    {
        $section = $this->fullSection(2);
        $registrar = $this->makeUser('branch_admin', $this->branchA->id, ['manage-students']);   // no override right

        $this->actingAs($registrar)->post('/students', $this->enrolPayload($section))->assertSessionHasErrors('section_id');
        $this->assertNull(Student::where('first_name', 'Newcomer')->first(), 'no half-created student');
        $this->assertSame(2, Enrollment::where('section_id', $section->id)->count());
        $this->assertStringContainsString('full', session('errors')->first('section_id'));

        // asking for an override without the right changes nothing -- only someone who actually
        // holds override-section-capacity is ever auto-placed (see test_an_administrator_... below)
        $this->actingAs($registrar)->post('/students', $this->enrolPayload($section, ['override_capacity' => true, 'override_reason' => 'please']))->assertSessionHasErrors('section_id');
        $this->assertNull(Student::where('first_name', 'Newcomer')->first());
    }

    public function test_room_left_lets_the_last_seat_be_taken(): void
    {
        $section = $this->fullSection(2);
        Enrollment::where('section_id', $section->id)->first()->update(['status' => 'withdrawn']);   // frees a seat
        $registrar = $this->makeUser('branch_admin', $this->branchA->id, ['manage-students']);   // no override right -- still genuinely blocked once full again

        $this->actingAs($registrar)->post('/students', $this->enrolPayload($section))->assertSessionHasNoErrors();
        $this->assertNotNull(Student::where('first_name', 'Newcomer')->first());
        $this->actingAs($registrar)->post('/students', $this->enrolPayload($section, ['first_name' => 'Another']))->assertSessionHasErrors('section_id');
    }

    public function test_an_administrator_can_override_with_a_reason_that_is_audited(): void
    {
        $section = $this->fullSection(1);
        $admin = $this->makeUser('branch_admin', $this->branchA->id);
        $this->assertTrue($admin->can('override-section-capacity'));

        $this->actingAs($admin)->post('/students', $this->enrolPayload($section, ['override_capacity' => true, 'override_reason' => 'Sibling of a current student']))->assertSessionHasNoErrors();

        $this->assertNotNull(Student::where('first_name', 'Newcomer')->first());
        $log = AuditLog::where('action', 'section.capacity_overridden')->firstOrFail();
        $this->assertSame('Sibling of a current student', $log->note);
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame(1, $log->before['capacity']);
        $this->assertSame(1, $log->after['over_by']);
        $this->assertSame(Section::class, $log->auditable_type);
    }

    /** A principal/owner is auto-placed over capacity with no checkbox and no reason -- it just happens,
     * logged with a default note, and the branch's own principal plus the owner are notified. */
    public function test_a_principal_is_auto_placed_over_capacity_with_no_reason_and_the_branch_and_owner_are_notified(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        $section = $this->fullSection(1);
        $principal = $this->makeUser('branch_admin', $this->branchA->id);
        $otherBranchPrincipal = $this->makeUser('branch_admin', $this->branchB->id);
        $this->assertTrue($principal->can('override-section-capacity'));

        $this->actingAs($principal)->post('/students', $this->enrolPayload($section))->assertSessionHasNoErrors();

        $this->assertNotNull(Student::where('first_name', 'Newcomer')->first());
        $log = AuditLog::where('action', 'section.capacity_overridden')->firstOrFail();
        $this->assertStringContainsString('automatically', $log->note);

        \Illuminate\Support\Facades\Notification::assertSentTo($this->owner, \App\Notifications\SectionCapacityExceeded::class);
        \Illuminate\Support\Facades\Notification::assertSentTo($principal, \App\Notifications\SectionCapacityExceeded::class);
        \Illuminate\Support\Facades\Notification::assertNotSentTo($otherBranchPrincipal, \App\Notifications\SectionCapacityExceeded::class);
    }

    /** Regression: a new notification type that isn't taught to NotificationPresenter falls through to its
     * `default => null` and becomes an unclickable dead end -- the same bug NewParentMessage had. */
    public function test_the_over_capacity_notification_links_the_owner_to_that_class(): void
    {
        $section = $this->fullSection(1);
        $principal = $this->makeUser('branch_admin', $this->branchA->id);
        $this->actingAs($principal)->post('/students', $this->enrolPayload($section))->assertSessionHasNoErrors();

        $presented = \App\Support\NotificationPresenter::present($this->owner->notifications()->firstOrFail());
        $this->assertSame(route('teaching-assignments.index', ['section' => $section->id]), $presented['action_url']);
        $this->actingAs($this->owner)->get($presented['action_url'])->assertOk();
    }

    /** A class can be decided later: give a branch instead of a section, and no enrollment is created yet. */
    public function test_a_student_can_be_registered_with_no_class_yet_and_placed_afterward(): void
    {
        $registrar = $this->makeUser('branch_admin', $this->branchA->id, ['manage-students']);

        $payload = ['first_name' => 'Undecided', 'gender' => 'male', 'enrollment_date' => '2026-09-20', 'branch_id' => $this->branchA->id, 'guardian_mode' => 'none', 'emergency_contact' => '0911223344'];
        $this->actingAs($registrar)->post('/students', $payload)->assertSessionHasNoErrors();

        $student = Student::where('first_name', 'Undecided')->firstOrFail();
        $this->assertSame($this->branchA->id, $student->branch_id);
        $this->assertSame(0, $student->enrollments()->count(), 'no enrollment exists yet');
        $this->assertNull($student->currentEnrollment);

        // placed later through the existing "move to another class" action -- no special casing needed
        $this->actingAs($registrar)->post("/students/{$student->id}/transfer", ['to_section_id' => $this->sectionA->id])->assertSessionHasNoErrors();
        $this->assertSame($this->sectionA->id, $student->fresh()->currentEnrollment->section_id);
    }

    public function test_registering_a_student_needs_either_a_section_or_a_branch(): void
    {
        $registrar = $this->makeUser('branch_admin', $this->branchA->id, ['manage-students']);

        $payload = ['first_name' => 'Nobody', 'gender' => 'male', 'enrollment_date' => '2026-09-20', 'guardian_mode' => 'none'];
        $this->actingAs($registrar)->post('/students', $payload)->assertSessionHasErrors(['section_id', 'branch_id']);
        $this->assertNull(Student::where('first_name', 'Nobody')->first());
    }

    public function test_a_section_without_capacity_is_unlimited(): void
    {
        $this->assertNull($this->sectionA->capacity);
        foreach (range(1, 3) as $i) {
            $this->actingAs($this->owner)->post('/students', $this->enrolPayload($this->sectionA, ['first_name' => "Kid{$i}"]))->assertSessionHasNoErrors();
        }
    }

    public function test_transfers_respect_capacity_and_the_same_override_rules(): void
    {
        $section = $this->fullSection(1);
        $registrar = $this->makeUser('branch_admin', $this->branchA->id, ['manage-students']);

        $this->actingAs($registrar)->post("/students/{$this->studentA->id}/transfer", ['to_section_id' => $section->id])->assertSessionHasErrors('to_section_id');
        $this->assertSame($this->sectionA->id, $this->studentA->fresh()->currentEnrollment->section_id, 'still where they were');

        $this->actingAs($this->owner)->post("/students/{$this->studentA->id}/transfer", ['to_section_id' => $section->id, 'override_capacity' => true, 'override_reason' => 'Parent request approved'])->assertSessionHasNoErrors();
        $this->assertSame($section->id, $this->studentA->fresh()->currentEnrollment->section_id);
    }

    public function test_promotion_stops_at_capacity_reports_it_and_can_be_overridden(): void
    {
        $y2 = AcademicYear::create(['school_id' => $this->school->id, 'name' => '2027', 'start_date' => '2027-09-01', 'end_date' => '2028-06-30', 'is_current' => false]);
        $g2 = Grade::create(['school_id' => $this->school->id, 'name' => 'G2', 'sort_order' => 2]);
        $to = Section::create(['branch_id' => $this->branchA->id, 'grade_id' => $g2->id, 'academic_year_id' => $y2->id, 'name' => 'A', 'capacity' => 2]);
        foreach (['B1', 'B2', 'B3'] as $i => $name) {
            $this->makeStudent($this->branchA, $this->sectionA, $name, "STU-P{$i}");
        }
        $rows = fn () => Enrollment::where('section_id', $this->sectionA->id)->where('status', 'active')->get()->map(fn ($e) => ['enrollment_id' => $e->id, 'graduate' => false])->all();   // 4 students
        $registrar = $this->makeUser('branch_admin', $this->branchA->id, ['manage-students']);

        $this->actingAs($registrar)->post('/promotions', ['from_section_id' => $this->sectionA->id, 'to_section_id' => $to->id, 'promotions' => $rows()])->assertSessionHasNoErrors();
        $this->assertSame(2, Enrollment::where('section_id', $to->id)->count());
        $this->assertStringContainsString('2 promoted', session('success'));
        $this->assertStringContainsString('2 not promoted because the destination section is full', session('success'));
        $this->assertSame(2, Enrollment::where('section_id', $this->sectionA->id)->where('status', 'active')->count(), 'the ones without a place stay where they were');

        // running it again with an override places the rest, with an audit entry
        $this->actingAs($this->owner)->post('/promotions', ['from_section_id' => $this->sectionA->id, 'to_section_id' => $to->id, 'override_capacity' => true, 'override_reason' => 'Two classes merging', 'promotions' => $rows()])->assertSessionHasNoErrors();
        $this->assertSame(4, Enrollment::where('section_id', $to->id)->count());
        $this->assertNotNull(AuditLog::where('action', 'section.capacity_overridden')->where('note', 'Two classes merging')->first());
    }

    public function test_reactivating_a_withdrawn_student_needs_a_seat(): void
    {
        $section = $this->fullSection(1);
        $student = Student::where('first_name', 'Seat0')->firstOrFail();
        $student->changeStatus('withdrawn');
        $this->makeStudent($this->branchA, $section, 'Taker', 'STU-T');   // someone else takes the freed seat

        $registrar = $this->makeUser('branch_admin', $this->branchA->id, ['manage-students']);
        $payload = ['first_name' => 'Seat0', 'gender' => 'female', 'status' => 'active'];
        $this->actingAs($registrar)->patch("/students/{$student->id}", $payload)->assertSessionHasErrors('status');
        $this->assertSame('withdrawn', $student->fresh()->status);

        $this->actingAs($this->owner)->patch("/students/{$student->id}", $payload + ['override_capacity' => true, 'override_reason' => 'Wrongly withdrawn'])->assertSessionHasNoErrors();
        $this->assertSame('active', $student->fresh()->status);
    }

    public function test_capacity_cannot_be_set_below_the_current_headcount(): void
    {
        $section = $this->fullSection(3);
        $this->actingAs($this->owner)->patch("/sections/{$section->id}", ['name' => 'Small', 'capacity' => 2])->assertSessionHasErrors('capacity');
        $this->assertSame(3, $section->fresh()->capacity);
        $this->actingAs($this->owner)->patch("/sections/{$section->id}", ['name' => 'Small', 'capacity' => 3])->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->patch("/sections/{$section->id}", ['name' => 'Small', 'capacity' => ''])->assertSessionHasNoErrors();
    }

    public function test_the_forms_are_told_how_many_seats_are_left(): void
    {
        $section = $this->fullSection(2);
        $props = $this->props($this->actingAs($this->owner)->get('/students/create'));
        $row = collect($props['sections'])->firstWhere('id', $section->id);
        $this->assertSame([2, 2, 0, true], [$row['capacity'], $row['occupied'], $row['seats_left'], $row['full']]);
        $this->assertTrue($props['canOverrideCapacity']);
    }

    private function makeUserIn($school)
    {
        $u = \App\Models\User::create(['school_id' => $school->id, 'name' => 'Boss', 'email' => 'boss@t.test', 'password' => 'x', 'status' => 'active', 'email_verified_at' => now()]);
        $u->assignTier('owner');

        return $u;
    }
}
