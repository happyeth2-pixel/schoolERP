<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Branch;
use App\Models\Grade;
use App\Models\School;
use App\Models\Section;
use App\Models\Student;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/** The date of birth is the only stored fact; age is always calculated from it. */
class StudentAgeTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_there_is_no_stored_age_column_to_drift_out_of_sync(): void
    {
        $columns = Schema::getColumnListing('students');

        $this->assertContains('date_of_birth', $columns);
        $this->assertSame([], array_values(array_filter($columns, fn ($c) => preg_match('/(^|_)age(_|$)/', $c))));
    }

    public function test_age_is_calculated_correctly_around_birthdays(): void
    {
        $s = new Student(['date_of_birth' => '2016-10-15']);

        $this->assertSame(9, $s->ageOn('2026-10-14'));   // day before the 10th birthday
        $this->assertSame(10, $s->ageOn('2026-10-15'));  // on the birthday
        $this->assertSame(10, $s->ageOn('2027-10-14'));
        $this->assertSame(0, $s->ageOn('2016-10-15'));   // day of birth
        $this->assertNull($s->ageOn('2016-10-14'), 'a day before they were born has no age');

        $leap = new Student(['date_of_birth' => '2016-02-29']);
        $this->assertSame(8, $leap->ageOn('2025-02-28'));
        $this->assertSame(9, $leap->ageOn('2025-03-01'));

        $this->assertNull((new Student)->ageOn('2026-10-14'), 'no date of birth, no age');
    }

    public function test_age_today_uses_the_addis_ababa_day(): void
    {
        Carbon::setTestNow('2026-10-14 22:30:00');   // 14 Oct in UTC, already 15 Oct in Addis

        $this->assertSame(10, (new Student(['date_of_birth' => '2016-10-15']))->age());
    }

    public function test_the_date_of_birth_must_be_believable_and_not_after_enrolment(): void
    {
        Carbon::setTestNow('2026-10-15 09:00:00');
        $this->seed(RolesAndPermissionsSeeder::class);
        $school = School::create(['name' => 'S', 'code' => 'S1']);
        $branch = Branch::create(['school_id' => $school->id, 'name' => 'Main', 'code' => 'M', 'is_main' => true]);
        $year = AcademicYear::create(['school_id' => $school->id, 'name' => '2026', 'start_date' => '2026-09-01', 'end_date' => '2027-06-30', 'is_current' => true]);
        $grade = Grade::create(['school_id' => $school->id, 'name' => 'G1', 'sort_order' => 1]);
        $section = Section::create(['branch_id' => $branch->id, 'grade_id' => $grade->id, 'academic_year_id' => $year->id, 'name' => 'A']);
        $owner = User::create(['school_id' => $school->id, 'name' => 'O', 'email' => 'o@t.test', 'password' => 'x', 'status' => 'active', 'email_verified_at' => now()]);
        $owner->assignTier('owner');

        $post = fn (array $over) => $this->actingAs($owner)->post('/students', array_merge([
            'first_name' => 'Kid', 'father_name' => 'One', 'gender' => 'male', 'enrollment_date' => '2026-09-20',
            'section_id' => $section->id, 'guardian_mode' => 'none', 'emergency_contact' => '0911223344', 'date_of_birth' => '2018-05-01',
        ], $over));

        $post(['date_of_birth' => '2026-10-16'])->assertSessionHasErrors('date_of_birth');   // tomorrow
        $post(['date_of_birth' => '1970-01-01'])->assertSessionHasErrors('date_of_birth');   // implausibly old
        $post(['date_of_birth' => '2026-09-25', 'enrollment_date' => '2026-09-20'])->assertSessionHasErrors('enrollment_date'); // enrolled before born
        $this->assertSame(0, Student::count());

        $post(['date_of_birth' => '2018-05-01'])->assertSessionHasNoErrors();
        $this->assertSame(8, Student::first()->age());
        $post(['first_name' => 'NoDob', 'date_of_birth' => null])->assertSessionHasNoErrors();   // optional
    }

    public function test_registering_with_an_age_instead_of_a_birthdate_computes_the_right_age(): void
    {
        Carbon::setTestNow('2026-10-15 09:00:00');
        $this->seed(RolesAndPermissionsSeeder::class);
        $school = School::create(['name' => 'S', 'code' => 'S2']);
        $branch = Branch::create(['school_id' => $school->id, 'name' => 'Main', 'code' => 'M', 'is_main' => true]);
        $year = AcademicYear::create(['school_id' => $school->id, 'name' => '2026', 'start_date' => '2026-09-01', 'end_date' => '2027-06-30', 'is_current' => true]);
        $grade = Grade::create(['school_id' => $school->id, 'name' => 'G1', 'sort_order' => 1]);
        $section = Section::create(['branch_id' => $branch->id, 'grade_id' => $grade->id, 'academic_year_id' => $year->id, 'name' => 'A']);
        $owner = User::create(['school_id' => $school->id, 'name' => 'O', 'email' => 'o2@t.test', 'password' => 'x', 'status' => 'active', 'email_verified_at' => now()]);
        $owner->assignTier('owner');

        $this->actingAs($owner)->post('/students', [
            'first_name' => 'Kid', 'father_name' => 'One', 'gender' => 'male', 'enrollment_date' => '2026-09-20',
            'section_id' => $section->id, 'guardian_mode' => 'none', 'emergency_contact' => '0911223344', 'age' => 8,
        ])->assertSessionHasNoErrors();

        $student = Student::firstOrFail();
        $this->assertSame(8, $student->age());
        $this->assertNotNull($student->date_of_birth);   // age is still stored as a real date_of_birth under the hood

        // editing without touching age keeps the same whole-year age
        $this->actingAs($owner)->put("/students/{$student->id}", [
            'first_name' => 'Kid', 'father_name' => 'One', 'gender' => 'male', 'age' => 8, 'status' => 'active',
        ])->assertSessionHasNoErrors();
        $this->assertSame(8, $student->fresh()->age());

        // an out-of-range age is refused, same as an implausible date_of_birth
        $this->actingAs($owner)->post('/students', [
            'first_name' => 'TooOld', 'father_name' => 'One', 'gender' => 'male', 'enrollment_date' => '2026-09-20',
            'section_id' => $section->id, 'guardian_mode' => 'none', 'emergency_contact' => '0911223344', 'age' => 99,
        ])->assertSessionHasErrors('age');
    }
}
