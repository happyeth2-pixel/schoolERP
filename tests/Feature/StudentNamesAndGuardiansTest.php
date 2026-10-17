<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Branch;
use App\Models\Grade;
use App\Models\School;
use App\Models\Section;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Names are flexible (only a first name is required) and a guardian is just a relationship. */
class StudentNamesAndGuardiansTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Section $section;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $school = School::create(['name' => 'S', 'code' => 'S'.Str::random(3)]);
        $branch = Branch::create(['school_id' => $school->id, 'name' => 'Main', 'code' => 'MAIN', 'is_main' => true]);
        $this->owner = User::create(['school_id' => $school->id, 'name' => 'O', 'email' => 'o@t.test', 'password' => 'x', 'status' => 'active', 'email_verified_at' => now()]);
        $this->owner->assignTier('owner');
        $year = AcademicYear::create(['school_id' => $school->id, 'name' => '2026', 'start_date' => '2026-09-01', 'end_date' => '2027-06-30', 'is_current' => true]);
        $grade = Grade::create(['school_id' => $school->id, 'name' => 'Grade 1', 'sort_order' => 1]);
        $this->section = Section::create(['branch_id' => $branch->id, 'grade_id' => $grade->id, 'academic_year_id' => $year->id, 'name' => 'A']);
    }

    private function payload(array $extra = []): array
    {
        return array_merge(['first_name' => 'Selam', 'gender' => 'female', 'enrollment_date' => '2026-09-20', 'section_id' => $this->section->id, 'guardian_mode' => 'none'], $extra);
    }

    public function test_only_a_first_name_is_required(): void
    {
        $this->actingAs($this->owner)->post('/students', $this->payload())->assertSessionHasNoErrors();
        $s = Student::where('first_name', 'Selam')->firstOrFail();
        $this->assertNull($s->father_name);
        $this->assertSame('Selam', $s->publicName());
        $this->assertSame('Selam', $s->fullName());

        $this->actingAs($this->owner)->post('/students', $this->payload(['first_name' => '']))->assertSessionHasErrors('first_name');
    }

    public function test_names_with_missing_parts_join_cleanly(): void
    {
        $s = new Student(['first_name' => 'Abebe', 'father_name' => null, 'grandfather_name' => 'Kebede']);
        $this->assertSame('Abebe Kebede', $s->fullName());
    }

    public function test_guardian_relationships_are_independent_of_the_biological_family(): void
    {
        foreach (['grandparent', 'aunt', 'uncle', 'foster_parent', 'sibling', 'other'] as $i => $rel) {
            $this->actingAs($this->owner)->post('/students', $this->payload([
                'first_name' => "Kid{$i}", 'guardian_mode' => 'new', 'guardian_name' => "G{$i}", 'guardian_email' => "g{$i}@t.test", 'guardian_relationship' => $rel,
            ]))->assertSessionHasNoErrors();
            $this->assertSame($rel, Student::where('first_name', "Kid{$i}")->first()->guardians()->first()->pivot->relationship);
        }
    }

    public function test_an_unknown_relationship_is_rejected_and_two_guardians_can_share_a_student(): void
    {
        $this->actingAs($this->owner)->post('/students', $this->payload(['guardian_mode' => 'new', 'guardian_name' => 'G', 'guardian_email' => 'g@t.test', 'guardian_relationship' => 'cousin-twice-removed']))
            ->assertSessionHasErrors('guardian_relationship');

        $this->actingAs($this->owner)->post('/students', $this->payload(['guardian_mode' => 'new', 'guardian_name' => 'Aunt A', 'guardian_email' => 'a@t.test', 'guardian_relationship' => 'aunt']))->assertSessionHasNoErrors();
        $s = Student::first();
        $this->actingAs($this->owner)->post("/students/{$s->id}/guardians", ['guardian_mode' => 'new', 'guardian_name' => 'Gran', 'guardian_email' => 'gran@t.test', 'guardian_relationship' => 'grandparent'])->assertSessionHasNoErrors();
        $this->assertSame(2, $s->guardians()->count());
    }
}
