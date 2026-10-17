<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\StaffAttendance;
use App\Models\StaffAttendanceCorrection;
use App\Models\User;
use App\Notifications\AttendanceCorrectionRequested;
use App\Notifications\AttendanceCorrectionReviewed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

/** HR module Phase C: staff attendance, HR/admin-recorded for everyone (confirmed via AskUserQuestion -- no
 * self-service check-in this phase). The first entry for a day is a direct write; changing an already-
 * recorded day goes through a StaffAttendanceCorrection requiring approval from someone OTHER than whoever
 * requested it -- the same "a requester can never approve their own request" rule Procurement already uses. */
class StaffAttendanceTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchool();
    }

    private function hrManager(?int $branchId = null): User
    {
        return $this->makeUser('branch_admin', $branchId, ['staff-attendance-view', 'staff-attendance-manage']);
    }

    private function employee(?int $branchId = null): Employee
    {
        return Employee::create([
            'school_id' => $this->school->id, 'branch_id' => $branchId, 'employee_number' => 'EMP-2026-000001',
            'full_name' => 'Hiwot Alemu', 'phone' => '0911000001', 'hire_date' => '2026-01-01',
            'employment_status' => 'active', 'employment_type' => 'full_time', 'created_by' => $this->owner->id,
        ]);
    }

    public function test_attendance_can_be_recorded_for_employees_not_yet_marked(): void
    {
        $manager = $this->hrManager($this->branchA->id);
        $employee = $this->employee($this->branchA->id);

        $this->actingAs($manager)->post('/staff-attendance', [
            'date' => '2026-02-01',
            'records' => [['employee_id' => $employee->id, 'status' => 'present', 'check_in' => '08:00', 'check_out' => '17:00']],
        ])->assertSessionHasNoErrors();

        $record = StaffAttendance::firstOrFail();
        $this->assertSame('present', $record->status);
        $this->assertSame($manager->id, $record->recorded_by);
    }

    public function test_recording_a_second_time_for_the_same_day_is_skipped_not_overwritten(): void
    {
        $manager = $this->hrManager();
        $employee = $this->employee();
        StaffAttendance::create(['employee_id' => $employee->id, 'date' => '2026-02-01', 'status' => 'present', 'recorded_by' => $manager->id]);

        $this->actingAs($manager)->post('/staff-attendance', [
            'date' => '2026-02-01',
            'records' => [['employee_id' => $employee->id, 'status' => 'absent']],
        ])->assertSessionHasNoErrors()->assertRedirect(); // must be a graceful skip, never a 500 from a duplicate-insert constraint failure

        $this->assertSame(1, StaffAttendance::count());
        $this->assertSame('present', StaffAttendance::firstOrFail()->status, 'an already-recorded day is never silently overwritten -- only a correction can change it');
    }

    public function test_a_correction_requires_approval_from_someone_other_than_the_requester(): void
    {
        Notification::fake();
        $requester = $this->hrManager($this->branchA->id);
        $otherManager = $this->hrManager($this->branchA->id);
        $employee = $this->employee($this->branchA->id);
        $attendance = StaffAttendance::create(['employee_id' => $employee->id, 'date' => '2026-02-01', 'status' => 'present', 'recorded_by' => $requester->id]);

        $this->actingAs($requester)->post("/staff-attendance/{$attendance->id}/corrections", [
            'new_status' => 'late', 'new_check_in' => '09:15', 'reason' => 'Forgot to note the late arrival.',
        ])->assertSessionHasNoErrors();

        $correction = StaffAttendanceCorrection::firstOrFail();
        $this->assertSame('pending', $correction->status);
        Notification::assertSentTo($otherManager, AttendanceCorrectionRequested::class);
        Notification::assertNotSentTo($requester, AttendanceCorrectionRequested::class);

        $this->actingAs($requester)->post("/staff-attendance-corrections/{$correction->id}/approve")->assertForbidden();

        $this->actingAs($otherManager)->post("/staff-attendance-corrections/{$correction->id}/approve")->assertRedirect();
        $this->assertSame('late', $attendance->fresh()->status);
        $this->assertSame('approved', $correction->fresh()->status);
        Notification::assertSentTo($requester, AttendanceCorrectionReviewed::class);
    }

    public function test_rejecting_a_correction_leaves_the_attendance_record_unchanged(): void
    {
        $requester = $this->hrManager();
        $otherManager = $this->hrManager();
        $employee = $this->employee();
        $attendance = StaffAttendance::create(['employee_id' => $employee->id, 'date' => '2026-02-01', 'status' => 'present', 'recorded_by' => $requester->id]);
        $this->actingAs($requester)->post("/staff-attendance/{$attendance->id}/corrections", ['new_status' => 'absent', 'reason' => 'Mistaken entry.']);
        $correction = StaffAttendanceCorrection::firstOrFail();

        $this->actingAs($otherManager)->post("/staff-attendance-corrections/{$correction->id}/reject")->assertRedirect();

        $this->assertSame('present', $attendance->fresh()->status);
        $this->assertSame('rejected', $correction->fresh()->status);
    }

    public function test_only_one_pending_correction_is_allowed_at_a_time(): void
    {
        $manager = $this->hrManager();
        $employee = $this->employee();
        $attendance = StaffAttendance::create(['employee_id' => $employee->id, 'date' => '2026-02-01', 'status' => 'present', 'recorded_by' => $manager->id]);
        $this->actingAs($manager)->post("/staff-attendance/{$attendance->id}/corrections", ['new_status' => 'late', 'reason' => 'First request.']);

        $this->actingAs($manager)->post("/staff-attendance/{$attendance->id}/corrections", ['new_status' => 'absent', 'reason' => 'Second request.'])
            ->assertStatus(422);

        $this->assertSame(1, StaffAttendanceCorrection::count());
    }

    public function test_the_roster_page_still_loads_once_a_pending_correction_exists(): void
    {
        $requester = $this->hrManager();
        $otherManager = $this->hrManager();
        $employee = $this->employee();
        $attendance = StaffAttendance::create(['employee_id' => $employee->id, 'date' => '2026-02-01', 'status' => 'present', 'recorded_by' => $requester->id]);
        $this->actingAs($requester)->post("/staff-attendance/{$attendance->id}/corrections", ['new_status' => 'late', 'reason' => 'Testing the roster still renders.']);

        $asRequester = $this->props($this->actingAs($requester)->get('/staff-attendance?date=2026-02-01')->assertOk());
        $row = collect($asRequester['roster'])->firstWhere('employee_id', $employee->id);
        $this->assertFalse($row['pending_correction']['can_review'], 'the requester must never be offered review of their own request');

        $asOther = $this->props($this->actingAs($otherManager)->get('/staff-attendance?date=2026-02-01')->assertOk());
        $row = collect($asOther['roster'])->firstWhere('employee_id', $employee->id);
        $this->assertTrue($row['pending_correction']['can_review']);
    }

    public function test_a_branch_admin_only_sees_their_own_branchs_roster_and_corrections(): void
    {
        $managerA = $this->hrManager($this->branchA->id);
        $employeeB = $this->employee($this->branchB->id);
        StaffAttendance::create(['employee_id' => $employeeB->id, 'date' => '2026-02-01', 'status' => 'present', 'recorded_by' => $this->owner->id]);

        $response = $this->actingAs($managerA)->get('/staff-attendance?date=2026-02-01');
        $names = collect($this->props($response)['roster'])->pluck('full_name');
        $this->assertFalse($names->contains('Hiwot Alemu'));
    }

    /** §26 hardening: the save loop now runs inside one transaction -- an out-of-branch employee partway
     * through a multi-person roster save must roll back the whole request, not leave the valid employee's
     * row saved while the rest silently never get marked. */
    public function test_an_out_of_branch_employee_partway_through_a_roster_save_rolls_back_the_whole_request(): void
    {
        $manager = $this->hrManager($this->branchA->id);
        $employeeA = $this->employee($this->branchA->id);
        $employeeB = Employee::create([
            'school_id' => $this->school->id, 'branch_id' => $this->branchB->id, 'employee_number' => 'EMP-2026-000002',
            'full_name' => 'Dawit Bekele', 'phone' => '0911000002', 'hire_date' => '2026-01-01',
            'employment_status' => 'active', 'employment_type' => 'full_time', 'created_by' => $this->owner->id,
        ]);

        $this->actingAs($manager)->post('/staff-attendance', [
            'date' => '2026-02-01',
            'records' => [
                ['employee_id' => $employeeA->id, 'status' => 'present'], // valid, same branch
                ['employee_id' => $employeeB->id, 'status' => 'present'], // East branch -- manager has no scope here
            ],
        ])->assertForbidden();

        $this->assertSame(0, StaffAttendance::count(), 'the valid first record must not have been left saved once the second one aborted');
    }

    public function test_someone_without_any_staff_attendance_permission_is_refused(): void
    {
        $bystander = $this->makeUser('teacher', $this->branchA->id, ['mark-attendance']);
        $this->actingAs($bystander)->get('/staff-attendance')->assertForbidden();
        $this->actingAs($bystander)->post('/staff-attendance', ['date' => '2026-02-01', 'records' => []])->assertForbidden();
    }
}
