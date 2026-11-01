<?php

namespace Tests\Feature;

use App\Models\OptionalService;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

/**
 * Enrolling several students in one optional service at once (OptionalServiceController::enrollForm/
 * bulkEnroll), the faster alternative to opening each student's own profile -- every student still goes
 * through the exact same StudentServices::enroll() rule as the one-at-a-time path.
 */
class BulkServiceEnrollmentTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    private User $admin;
    private OptionalService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchool();
        $this->admin = $this->makeUser('branch_admin', $this->branchA->id, ['manage-invoices']);
        $this->service = OptionalService::create([
            'branch_id' => $this->branchA->id, 'academic_year_id' => $this->year->id,
            'name' => 'Chess Club', 'category' => 'asp', 'amount' => 300, 'frequency' => 'monthly',
        ]);
    }

    public function test_the_form_lists_this_branchs_classes_and_students_marking_who_is_already_in(): void
    {
        $second = $this->makeStudent($this->branchA, $this->sectionA, 'Second', 'STU-A2');
        $this->service->students()->attach($second->id, ['enrolled_on' => now()->toDateString()]);

        $page = $this->props($this->actingAs($this->admin)->get(route('optional-services.enroll-form', $this->service))->assertOk());

        $names = collect($page['students'])->pluck('name');
        $this->assertTrue($names->contains($this->studentA->fullName()));
        $this->assertTrue($names->contains($second->fullName()));

        $secondRow = collect($page['students'])->firstWhere('id', $second->id);
        $this->assertTrue($secondRow['already_enrolled']);
        $studentARow = collect($page['students'])->firstWhere('id', $this->studentA->id);
        $this->assertFalse($studentARow['already_enrolled']);
    }

    public function test_enrolling_several_students_at_once_enrolls_each_one(): void
    {
        $second = $this->makeStudent($this->branchA, $this->sectionA, 'Second', 'STU-A2');
        $third = $this->makeStudent($this->branchA, $this->sectionA, 'Third', 'STU-A3');

        $this->actingAs($this->admin)->post(route('optional-services.bulk-enroll', $this->service), [
            'student_ids' => [$this->studentA->id, $second->id, $third->id],
        ])->assertSessionHasNoErrors();

        $this->assertTrue($this->studentA->optionalServices()->whereKey($this->service->id)->exists());
        $this->assertTrue($second->optionalServices()->whereKey($this->service->id)->exists());
        $this->assertTrue($third->optionalServices()->whereKey($this->service->id)->exists());
    }

    public function test_a_student_already_enrolled_is_skipped_not_failed(): void
    {
        $second = $this->makeStudent($this->branchA, $this->sectionA, 'Second', 'STU-A2');
        $this->service->students()->attach($second->id, ['enrolled_on' => now()->toDateString()]);

        $response = $this->actingAs($this->admin)->post(route('optional-services.bulk-enroll', $this->service), [
            'student_ids' => [$this->studentA->id, $second->id],
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertTrue($this->studentA->optionalServices()->whereKey($this->service->id)->exists());
        // still exactly one row for the already-enrolled student -- not duplicated, not errored away
        $this->assertSame(1, $second->optionalServices()->whereKey($this->service->id)->count());
    }

    public function test_a_custom_amount_applies_to_every_student_enrolled_this_way(): void
    {
        $second = $this->makeStudent($this->branchA, $this->sectionA, 'Second', 'STU-A2');

        $this->actingAs($this->admin)->post(route('optional-services.bulk-enroll', $this->service), [
            'student_ids' => [$this->studentA->id, $second->id],
            'custom_amount' => 150,
        ]);

        $this->assertEquals(150, $this->studentA->optionalServices()->whereKey($this->service->id)->first()->pivot->custom_amount);
        $this->assertEquals(150, $second->optionalServices()->whereKey($this->service->id)->first()->pivot->custom_amount);
    }

    public function test_only_someone_who_may_manage_invoices_can_bulk_enroll(): void
    {
        $noPermission = $this->makeUser('branch_admin', $this->branchA->id, []);

        $this->actingAs($noPermission)->get(route('optional-services.enroll-form', $this->service))->assertForbidden();
        $this->actingAs($noPermission)->post(route('optional-services.bulk-enroll', $this->service), ['student_ids' => [$this->studentA->id]])->assertForbidden();
    }

    public function test_a_student_outside_the_services_branch_is_simply_not_enrolled(): void
    {
        $otherBranchStudent = $this->makeStudent($this->branchB, $this->sectionB, 'Other', 'STU-B1');

        $this->actingAs($this->admin)->post(route('optional-services.bulk-enroll', $this->service), [
            'student_ids' => [$otherBranchStudent->id],
        ])->assertSessionHasNoErrors();

        $this->assertFalse($otherBranchStudent->optionalServices()->whereKey($this->service->id)->exists());
    }
}
