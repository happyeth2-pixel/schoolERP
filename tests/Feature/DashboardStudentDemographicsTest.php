<?php

namespace Tests\Feature;

use App\Models\Enrollment;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

/** The "Student Statistics" panel on the dashboard (a male/female headcount per academic year, plus the
 * current grade split) -- shown to owner, branch_admin, auditor, and accountant, not owner-only. */
class DashboardStudentDemographicsTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    private function enroll(string $gender, string $number): Student
    {
        $s = Student::create([
            'school_id' => $this->school->id, 'branch_id' => $this->branchA->id, 'student_id_number' => $number,
            'first_name' => 'Stu', 'father_name' => 'Dent', 'gender' => $gender,
            'enrollment_date' => '2026-09-01', 'status' => 'active', 'id_card_token' => Student::newCardToken(),
        ]);
        Enrollment::create(['student_id' => $s->id, 'section_id' => $this->sectionA->id, 'enrolled_on' => '2026-09-01', 'status' => 'active']);

        return $s;
    }

    public function test_gender_grade_and_per_year_statistics_are_computed_correctly(): void
    {
        $this->buildSchool();
        // buildSchool() already enrolls two baseline students (studentA in branch A, studentB in branch
        // B, both female) into the one academic year it creates -- accounted for below rather than hidden.
        $this->enroll('male', 'S1');
        $this->enroll('male', 'S2');
        $this->enroll('female', 'S3');

        $owner = $this->owner;
        $demographics = $this->props($this->actingAs($owner)->get('/dashboard'))['stats']['demographics'];

        $this->assertSame(5, $demographics['total']);
        $this->assertSame(['male' => 2, 'female' => 3], $demographics['by_gender']);

        $grade = collect($demographics['by_grade'])->firstWhere('grade', $this->grade->name);
        $this->assertSame(5, $grade['count']);

        $this->assertCount(1, $demographics['by_year_gender']);
        $this->assertSame(['year' => $this->year->name, 'male' => 2, 'female' => 3], $demographics['by_year_gender'][0]);
    }

    public function test_an_accountant_also_sees_student_demographics_not_owner_only(): void
    {
        $this->buildSchool();
        $this->enroll('male', 'S1');
        $accountant = $this->makeUser('accountant', null, ['view-financial-reports', 'verify-payments']);

        $demographics = $this->props($this->actingAs($accountant)->get('/dashboard'))['stats']['demographics'];

        $this->assertSame(3, $demographics['total']); // 1 new + the 2 baseline students
    }

    public function test_demographics_are_scoped_to_the_viewers_own_branch(): void
    {
        $this->buildSchool();
        $this->enroll('male', 'S1'); // branch A -- out of branchAdminB's scope below
        $branchAdminB = $this->makeUser('branch_admin', $this->branchB->id, ['manage-students']);

        $demographics = $this->props($this->actingAs($branchAdminB)->get('/dashboard'))['stats']['demographics'];

        $this->assertSame(1, $demographics['total']); // just the baseline studentB
        $this->assertSame(['year' => $this->year->name, 'male' => 0, 'female' => 1], $demographics['by_year_gender'][0]);
    }
}
