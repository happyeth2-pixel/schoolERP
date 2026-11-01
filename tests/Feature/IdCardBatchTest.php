<?php

namespace Tests\Feature;

use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

/**
 * The whole-class print sheet (/sections/{section}/id-cards, IdCardController@batch) -- a registrar's
 * actual workflow is printing a class at once, not opening each student one at a time. Every active
 * student in the section, each carrying the same fields the single card does (plus age/location/
 * mother/father, the fields IdCardController::cardPayload() added), scoped the same
 * way StudentPolicy::viewRecords() scopes the single card -- but checked once against the section's own
 * branch, since every student in a section already shares it.
 */
class IdCardBatchTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchool();
    }

    private function batch(User $as, $section)
    {
        return $this->actingAs($as)->get(route('id-card.batch', $section));
    }

    public function test_the_sheet_carries_every_active_student_with_the_full_card_including_the_new_fields(): void
    {
        // A relative date of birth, not a fixed calendar one: this only pins that the card's age field is
        // whatever Student::age() itself computes, not a second independent calculation -- IdCardVerificationTest
        // already covers age/date edge cases in depth.
        $this->studentA->update(['date_of_birth' => now()->subYears(11)->toDateString(), 'address' => 'Bole, Addis Ababa', 'mother_name' => 'Hiwot', 'father_name' => 'Tesfaye']);
        $second = $this->makeStudent($this->branchA, $this->sectionA, 'Second', 'STU-A2');

        $page = $this->props($this->batch($this->owner, $this->sectionA)->assertOk());

        $this->assertSame('G1 A', $page['section']['label']);
        $names = collect($page['students'])->pluck('name')->all();
        $this->assertEqualsCanonicalizing(['Almaz Tesfaye', 'Second Tesfaye'], $names);

        $almaz = collect($page['students'])->firstWhere('student_id_number', 'STU-A');
        $this->assertSame(11, $almaz['age'], 'the card reads Student::age() for whatever "today" actually is');
        $this->assertSame('Bole, Addis Ababa', $almaz['location']);
        $this->assertSame('Hiwot', $almaz['mother_name']);
        $this->assertSame('Tesfaye', $almaz['father_name']);
        $this->assertNotNull($almaz['verify_url']);
        $this->assertSame('valid', $almaz['card_validity']['state']);
    }

    public function test_a_withdrawn_students_old_enrollment_never_appears_on_the_sheet(): void
    {
        $second = $this->makeStudent($this->branchA, $this->sectionA, 'Second', 'STU-A2');
        Enrollment::where('student_id', $second->id)->update(['status' => 'withdrawn']);
        $second->update(['status' => 'withdrawn']);

        $page = $this->props($this->batch($this->owner, $this->sectionA)->assertOk());

        $this->assertSame(['Almaz Tesfaye'], collect($page['students'])->pluck('name')->all());
    }

    public function test_only_someone_who_manages_students_in_scope_or_an_auditor_may_open_the_sheet(): void
    {
        $manager = $this->makeUser('branch_admin', $this->branchA->id, ['manage-students']);
        $wrongBranch = $this->makeUser('branch_admin', $this->branchB->id, ['manage-students']);
        $noPermission = $this->makeUser('branch_admin', $this->branchA->id, []);
        $auditor = $this->makeUser('auditor', null);

        $this->batch($manager, $this->sectionA)->assertOk();
        $this->batch($auditor, $this->sectionA)->assertOk();
        $this->batch($wrongBranch, $this->sectionA)->assertForbidden();
        $this->batch($noPermission, $this->sectionA)->assertForbidden();
    }

    public function test_a_section_from_another_school_entirely_is_not_reachable(): void
    {
        $otherSchool = \App\Models\School::create(['name' => 'Other', 'code' => 'OTH']);
        $otherBranch = \App\Models\Branch::create(['school_id' => $otherSchool->id, 'name' => 'Main', 'code' => 'OM', 'is_main' => true]);
        $otherYear = \App\Models\AcademicYear::create(['school_id' => $otherSchool->id, 'name' => '2026', 'start_date' => '2026-09-01', 'end_date' => '2027-06-30', 'is_current' => true]);
        $otherGrade = \App\Models\Grade::create(['school_id' => $otherSchool->id, 'name' => 'G1', 'sort_order' => 1]);
        $otherSection = \App\Models\Section::create(['branch_id' => $otherBranch->id, 'grade_id' => $otherGrade->id, 'academic_year_id' => $otherYear->id, 'name' => 'A']);

        $this->batch($this->owner, $otherSection)->assertNotFound();
    }
}
