<?php

namespace Tests\Concerns;

use App\Models\AcademicYear;
use App\Models\Branch;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\School;
use App\Models\Section;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;

/** A small two-branch school: one grade, a section per branch, a student per branch, an owner. */
trait BuildsSchool
{
    protected School $school;
    protected Branch $branchA;
    protected Branch $branchB;
    protected User $owner;
    protected AcademicYear $year;
    protected Grade $grade;
    protected Section $sectionA;
    protected Section $sectionB;
    protected Student $studentA;
    protected Student $studentB;

    protected function buildSchool(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->school = School::create(['name' => 'S', 'code' => 'S'.Str::random(3)]);
        $this->branchA = Branch::create(['school_id' => $this->school->id, 'name' => 'Main', 'code' => 'MAIN', 'is_main' => true]);
        $this->branchB = Branch::create(['school_id' => $this->school->id, 'name' => 'East', 'code' => 'EAST', 'is_main' => false]);
        $this->owner = $this->makeUser('owner', null);
        $this->year = AcademicYear::create(['school_id' => $this->school->id, 'name' => '2026', 'start_date' => '2026-09-01', 'end_date' => '2027-06-30', 'is_current' => true]);
        $this->grade = Grade::create(['school_id' => $this->school->id, 'name' => 'G1', 'sort_order' => 1]);
        $this->sectionA = Section::create(['branch_id' => $this->branchA->id, 'grade_id' => $this->grade->id, 'academic_year_id' => $this->year->id, 'name' => 'A']);
        $this->sectionB = Section::create(['branch_id' => $this->branchB->id, 'grade_id' => $this->grade->id, 'academic_year_id' => $this->year->id, 'name' => 'B']);
        $this->studentA = $this->makeStudent($this->branchA, $this->sectionA, 'Almaz', 'STU-A');
        $this->studentB = $this->makeStudent($this->branchB, $this->sectionB, 'Berhanu', 'STU-B');
    }

    protected function makeUser(string $role, ?int $branchId, array $permissions = null): User
    {
        $u = User::create(['school_id' => $this->school->id, 'branch_id' => $branchId, 'name' => ucfirst($role).' '.Str::random(3), 'email' => Str::random(8).'@t.test', 'password' => 'x', 'status' => 'active', 'email_verified_at' => now()]);
        $u->assignTier($role);
        if ($permissions !== null) {
            $u->syncPermissions($permissions);
        }

        return $u;
    }

    protected function makeStudent(Branch $branch, Section $section, string $name, string $number): Student
    {
        $s = Student::create(['school_id' => $this->school->id, 'branch_id' => $branch->id, 'student_id_number' => $number, 'first_name' => $name, 'father_name' => 'Tesfaye', 'gender' => 'female', 'enrollment_date' => '2026-09-01', 'status' => 'active', 'id_card_token' => Student::newCardToken()]);
        Enrollment::create(['student_id' => $s->id, 'section_id' => $section->id, 'enrolled_on' => '2026-09-01', 'status' => 'active']);

        return $s;
    }

    protected function makeParent(Student ...$children): User
    {
        $p = $this->makeUser('parent', null);
        foreach ($children as $child) {
            $child->guardians()->attach($p->id, ['relationship' => 'mother']);
        }

        return $p;
    }

    protected function props($response): array
    {
        return $response->original->getData()['page']['props'];
    }
}
