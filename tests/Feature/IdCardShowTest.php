<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

/**
 * The staff-facing printable ID card (/id-card/{student}, IdCardController@show -- distinct from the public,
 * deliberately minimal /verify/{token} page covered by IdCardVerificationTest). Access follows the exact same
 * rule as the report card (StudentPolicy::viewRecords); this pins that the card's new identity fields (gender,
 * emergency contact, grade/section, academic year) are read from the student's own current enrollment -- never
 * a second, independently-chosen lookup -- and that the same access rule still blocks anyone outside it.
 */
class IdCardShowTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchool();
    }

    private function show(User $as, Student $student)
    {
        return $this->actingAs($as)->get("/students/{$student->id}/id-card");
    }

    public function test_the_card_carries_every_new_identity_field_from_the_students_own_current_enrollment(): void
    {
        $this->studentA->update(['gender' => 'female', 'emergency_contact' => PhoneNumber::normalize('0911223344'), 'photo_path' => 'student-photos/abc.jpg']);

        $page = $this->props($this->show($this->owner, $this->studentA)->assertOk())['student'];

        $this->assertSame('Almaz Tesfaye', $page['name']);
        $this->assertSame('STU-A', $page['student_id_number']);
        $this->assertSame('female', $page['gender']);
        $this->assertSame('0911 22 33 44', $page['emergency_contact']);
        $this->assertSame('G1', $page['grade']);
        $this->assertSame('A', $page['section']);
        $this->assertSame('2026', $page['academic_year']);
        $this->assertStringContainsString('abc.jpg', $page['photo_url']);
    }

    public function test_a_student_with_no_current_enrollment_shows_no_class_fields_rather_than_an_error(): void
    {
        $s = Student::create(['school_id' => $this->school->id, 'branch_id' => $this->branchA->id, 'student_id_number' => 'STU-X', 'first_name' => 'Loose', 'father_name' => 'End', 'gender' => 'male', 'enrollment_date' => '2026-09-01', 'status' => 'active', 'id_card_token' => Student::newCardToken()]);

        $page = $this->props($this->show($this->owner, $s)->assertOk())['student'];

        $this->assertNull($page['grade']);
        $this->assertNull($page['section']);
        $this->assertNull($page['academic_year']);
    }

    public function test_a_student_with_no_emergency_contact_or_photo_shows_null_not_an_empty_string(): void
    {
        $page = $this->props($this->show($this->owner, $this->studentA)->assertOk())['student'];

        $this->assertNull($page['emergency_contact']);
        $this->assertNull($page['photo_url']);
    }

    public function test_only_someone_who_may_view_the_students_records_can_open_the_card(): void
    {
        $registrar = $this->makeUser('branch_admin', $this->branchA->id, ['manage-students']);
        $this->show($registrar, $this->studentA)->assertOk();

        $teacher = $this->makeUser('teacher', $this->branchA->id);
        $this->show($teacher, $this->studentA)->assertForbidden();

        $auditor = $this->makeUser('auditor', null);
        $this->show($auditor, $this->studentA)->assertOk();
    }

    public function test_a_branch_admin_cannot_open_another_branchs_students_card(): void
    {
        $registrarA = $this->makeUser('branch_admin', $this->branchA->id, ['manage-students']);

        $this->show($registrarA, $this->studentB)->assertForbidden();
        $this->show($this->owner, $this->studentB)->assertOk();
    }

    public function test_a_parent_may_open_only_their_own_childs_card(): void
    {
        $parent = $this->makeParent($this->studentA);

        $this->show($parent, $this->studentA)->assertOk();
        // a parent/student denial is a plain 404, not a 403 -- same "reveals nothing" rule as the rest of the app
        $this->show($parent, $this->studentB)->assertNotFound();
    }

    public function test_a_card_from_another_school_entirely_is_not_reachable(): void
    {
        $otherSchool = \App\Models\School::create(['name' => 'Other', 'code' => 'OTH']);
        $otherBranch = \App\Models\Branch::create(['school_id' => $otherSchool->id, 'name' => 'Main', 'code' => 'MAIN', 'is_main' => true]);
        $otherStudent = Student::create(['school_id' => $otherSchool->id, 'branch_id' => $otherBranch->id, 'student_id_number' => 'STU-OTH', 'first_name' => 'Foreign', 'gender' => 'male', 'enrollment_date' => '2026-09-01', 'status' => 'active', 'id_card_token' => Student::newCardToken()]);

        $this->show($this->owner, $otherStudent)->assertForbidden();
    }
}
