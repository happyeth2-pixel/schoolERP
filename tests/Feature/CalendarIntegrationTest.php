<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Branch;
use App\Models\Enrollment;
use App\Models\FeeStructure;
use App\Models\Grade;
use App\Models\Invoice;
use App\Models\School;
use App\Models\Section;
use App\Models\Student;
use App\Models\User;
use App\Services\EthiopianCalendar as E;
use App\Support\SchoolCalendar;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Storage stays Gregorian/UTC; the calendar only changes how dates are decided
 * ("today"), shown, and (for Ethiopian schools) how months are billed.
 */
class CalendarIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_school_day_rolls_over_at_midnight_in_addis_not_at_utc(): void
    {
        Carbon::setTestNow('2026-10-14 22:30:00');   // UTC: still the 14th.  Addis Ababa (UTC+3): 01:30 on the 15th.

        $this->assertSame('2026-10-15', SchoolCalendar::today()->toDateString());

        Carbon::setTestNow('2026-10-14 20:59:00');   // 23:59 in Addis: still the 14th
        $this->assertSame('2026-10-14', SchoolCalendar::today()->toDateString());
    }

    public function test_attendance_and_dashboards_use_the_addis_day(): void
    {
        Carbon::setTestNow('2026-10-14 22:30:00');
        [$school] = $this->schoolWith('both');
        $owner = $this->user($school, 'owner', null);

        $props = $this->get_props($this->actingAs($owner)->get('/attendance'));
        $this->assertSame('2026-10-15', $props['date']);
    }

    public function test_dates_are_shown_in_the_schools_calendar_and_timestamps_in_addis_time(): void
    {
        [$eth] = $this->schoolWith('ethiopian');
        [$gre] = $this->schoolWith('gregorian', 'G');
        [$both] = $this->schoolWith('both', 'B');

        $this->assertSame('1 Meskerem 2018', SchoolCalendar::date('2025-09-11', $eth));
        $this->assertSame('Sep 11, 2025', SchoolCalendar::date('2025-09-11', $gre));
        $this->assertSame('1 Meskerem 2018 (Sep 11, 2025)', SchoolCalendar::date('2025-09-11', $both));
        $this->assertSame('', SchoolCalendar::date(null, $eth));

        // a UTC timestamp late on the 14th is the small hours of the 15th in Addis
        $this->assertSame('Oct 15, 2026 1:30 AM', SchoolCalendar::dateTime('2026-10-14 22:30:00', $gre));
        $this->assertSame('5 Tikimt 2019 1:30 AM', SchoolCalendar::dateTime('2026-10-14 22:30:00', $eth));
    }

    public function test_an_ethiopian_school_is_billed_by_ethiopian_months(): void
    {
        Carbon::setTestNow('2026-10-15 09:00:00');
        [$school, $branch] = $this->schoolWith('ethiopian');
        $owner = $this->user($school, 'owner', null);

        // year 2019 E.C.: Meskerem 1 (11 Sep 2026) to Sene 30 (7 Jul 2027)
        $year = AcademicYear::create(['school_id' => $school->id, 'name' => '2019 E.C.', 'start_date' => E::toIso(2019, 1, 1), 'end_date' => E::toIso(2019, 10, 30), 'is_current' => true]);
        $grade = Grade::create(['school_id' => $school->id, 'name' => 'Grade 1', 'sort_order' => 1]);
        $section = Section::create(['branch_id' => $branch->id, 'grade_id' => $grade->id, 'academic_year_id' => $year->id, 'name' => 'A']);
        $student = $this->student($school, $branch, $section);
        FeeStructure::create(['branch_id' => $branch->id, 'grade_id' => $grade->id, 'academic_year_id' => $year->id, 'name' => 'Monthly fee', 'amount' => 100, 'frequency' => 'monthly']);

        $this->actingAs($owner)->post('/invoices/generate', ['branch_id' => $branch->id, 'grade_id' => $grade->id])->assertSessionHasNoErrors();

        $invoices = Invoice::where('student_id', $student->id)->orderBy('period_start')->get();
        $this->assertCount(10, $invoices, 'Meskerem..Sene = 10 monthly invoices');
        foreach (range(1, 10) as $i => $month) {
            $this->assertSame(E::toIso(2019, $month, 1), $invoices[$i]->period_start->toDateString(), "month {$month} must start on the 1st of the Ethiopian month");
            $this->assertSame(E::monthName($month).' 2019', $invoices[$i]->period_label);
        }
        $this->assertSame('2026-09-11', $invoices[0]->period_start->toDateString(), 'stored as an ordinary Gregorian date');
    }

    public function test_a_gregorian_school_keeps_gregorian_month_billing(): void
    {
        Carbon::setTestNow('2026-10-15 09:00:00');
        [$school, $branch] = $this->schoolWith('gregorian');
        $owner = $this->user($school, 'owner', null);
        $year = AcademicYear::create(['school_id' => $school->id, 'name' => '2026', 'start_date' => '2026-09-01', 'end_date' => '2027-06-30', 'is_current' => true]);
        $grade = Grade::create(['school_id' => $school->id, 'name' => 'Grade 1', 'sort_order' => 1]);
        $section = Section::create(['branch_id' => $branch->id, 'grade_id' => $grade->id, 'academic_year_id' => $year->id, 'name' => 'A']);
        $student = $this->student($school, $branch, $section);
        FeeStructure::create(['branch_id' => $branch->id, 'grade_id' => $grade->id, 'academic_year_id' => $year->id, 'name' => 'Monthly fee', 'amount' => 100, 'frequency' => 'monthly']);

        $this->actingAs($owner)->post('/invoices/generate', ['branch_id' => $branch->id, 'grade_id' => $grade->id]);

        $invoices = Invoice::where('student_id', $student->id)->orderBy('period_start')->get();
        $this->assertCount(10, $invoices);
        $this->assertSame('September 2026', $invoices[0]->period_label);
        $this->assertSame('2026-09-01', $invoices[0]->period_start->toDateString());
    }

    public function test_the_withdrawal_date_limit_is_the_addis_day(): void
    {
        Carbon::setTestNow('2026-10-14 22:30:00');   // 15 Oct in Addis
        [$school, $branch] = $this->schoolWith('ethiopian');
        $owner = $this->user($school, 'owner', null);
        $year = AcademicYear::create(['school_id' => $school->id, 'name' => '2026', 'start_date' => '2026-09-01', 'end_date' => '2027-06-30', 'is_current' => true]);
        $grade = Grade::create(['school_id' => $school->id, 'name' => 'Grade 1', 'sort_order' => 1]);
        $section = Section::create(['branch_id' => $branch->id, 'grade_id' => $grade->id, 'academic_year_id' => $year->id, 'name' => 'A']);
        $student = $this->student($school, $branch, $section);

        $this->actingAs($owner)->post("/students/{$student->id}/withdraw", ['effective_date' => '2026-10-16', 'reason' => 'future'])->assertSessionHasErrors('effective_date');
        $this->actingAs($owner)->post("/students/{$student->id}/withdraw", ['effective_date' => '2026-10-15', 'reason' => 'left today'])->assertSessionHasNoErrors();
        $this->assertSame('withdrawn', $student->fresh()->status);
        $this->assertSame('2026-10-15', $student->fresh()->withdrawn_on->toDateString(), 'stored as a Gregorian date');
    }

    public function test_the_date_string_the_browser_sends_is_always_gregorian_iso(): void
    {
        // an Ethiopian school still stores/accepts plain Gregorian ISO dates: the browser converts before sending
        [$school, $branch] = $this->schoolWith('ethiopian');
        $owner = $this->user($school, 'owner', null);
        $year = AcademicYear::create(['school_id' => $school->id, 'name' => '2019', 'start_date' => E::toIso(2019, 1, 1), 'end_date' => E::toIso(2019, 10, 30), 'is_current' => true]);
        $grade = Grade::create(['school_id' => $school->id, 'name' => 'Grade 1', 'sort_order' => 1]);
        $section = Section::create(['branch_id' => $branch->id, 'grade_id' => $grade->id, 'academic_year_id' => $year->id, 'name' => 'A']);

        $this->actingAs($owner)->post('/students', [
            'first_name' => 'Kid', 'father_name' => 'One', 'gender' => 'male', 'date_of_birth' => E::toIso(2010, 5, 15),
            'enrollment_date' => '2026-09-20', 'section_id' => $section->id, 'guardian_mode' => 'none', 'emergency_contact' => '0911223344',
        ])->assertSessionHasNoErrors();

        $this->assertSame('2018-01-23', Student::where('first_name', 'Kid')->first()->date_of_birth->toDateString());
    }

    // ---------------------------------------------------------------- helpers

    private function schoolWith(string $preference, string $suffix = ''): array
    {
        $school = School::create(['name' => "School{$suffix}", 'code' => "S{$suffix}".Str::random(3), 'calendar_preference' => $preference]);
        $branch = Branch::create(['school_id' => $school->id, 'name' => 'Main', 'code' => 'MAIN', 'is_main' => true]);

        return [$school, $branch];
    }

    private function user(School $school, string $role, ?int $branchId): User
    {
        $user = User::create(['school_id' => $school->id, 'branch_id' => $branchId, 'name' => "U{$role}".Str::random(3), 'email' => Str::random(8).'@t.test', 'password' => 'x', 'status' => 'active', 'email_verified_at' => now()]);
        $user->assignTier($role);

        return $user;
    }

    private function student(School $school, Branch $branch, Section $section): Student
    {
        $student = Student::create(['school_id' => $school->id, 'branch_id' => $branch->id, 'student_id_number' => 'STU-'.Str::random(5), 'first_name' => 'Almaz', 'father_name' => 'Tesfaye', 'gender' => 'female', 'enrollment_date' => '2026-09-01', 'status' => 'active', 'id_card_token' => Student::newCardToken()]);
        Enrollment::create(['student_id' => $student->id, 'section_id' => $section->id, 'enrolled_on' => '2026-09-01', 'status' => 'active']);

        return $student;
    }

    private function get_props($response): array
    {
        return $response->original->getData()['page']['props'];
    }
}
