<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\StudentMedicalRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

/**
 * Academic expansion, Phase 4: Student Medical Records. The brief's own three explicit requirements --
 * permission, audit, never in the ordinary list -- are each pinned by their own test below. The most
 * important one: the audit trail itself must never leak the actual medical content, since `view-audit-log`
 * is a BROADER permission than `medical-records-view`/`manage` -- see `StudentMedicalRecord`'s class
 * docblock for why the `Audited` trait is deliberately not used here.
 */
class StudentMedicalRecordTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    private const SENTINEL = 'Severe-Peanut-Allergy-Sentinel-XYZ';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchool();
    }

    public function test_manage_students_alone_does_not_grant_access(): void
    {
        $registrar = $this->makeUser('branch_admin', $this->branchA->id, ['manage-students']);

        $this->actingAs($registrar)->get("/students/{$this->studentA->id}/medical")->assertForbidden();
    }

    public function test_view_permission_can_see_but_not_change(): void
    {
        $nurse = $this->makeUser('branch_admin', $this->branchA->id, ['medical-records-view']);

        $this->actingAs($nurse)->get("/students/{$this->studentA->id}/medical")->assertOk();
        $this->actingAs($nurse)->put("/students/{$this->studentA->id}/medical", ['allergies' => self::SENTINEL])->assertForbidden();
        $this->assertSame(0, StudentMedicalRecord::count());
    }

    public function test_manage_permission_can_view_and_save(): void
    {
        $nurse = $this->makeUser('branch_admin', $this->branchA->id, ['medical-records-manage']);

        $this->actingAs($nurse)->put("/students/{$this->studentA->id}/medical", [
            'blood_type' => 'O+', 'allergies' => self::SENTINEL, 'conditions' => 'Asthma', 'medications' => 'Inhaler',
            'physician_name' => 'Dr. Amanuel', 'physician_phone' => '0911000099', 'notes' => 'Carries an EpiPen.',
        ])->assertSessionHasNoErrors();

        $record = StudentMedicalRecord::firstOrFail();
        $this->assertSame($this->studentA->id, $record->student_id);
        $this->assertSame(self::SENTINEL, $record->allergies);
        $this->assertSame($nurse->id, $record->updated_by);

        $p = $this->props($this->actingAs($nurse)->get("/students/{$this->studentA->id}/medical"));
        $this->assertSame(self::SENTINEL, $p['record']['allergies']);
        $this->assertTrue($p['canManage']);
    }

    public function test_an_auditor_can_view_read_only_regardless_of_explicit_permission(): void
    {
        $auditor = $this->makeUser('auditor', null, []);

        $this->actingAs($auditor)->get("/students/{$this->studentA->id}/medical")->assertOk();
        $this->actingAs($auditor)->put("/students/{$this->studentA->id}/medical", ['allergies' => 'x'])->assertForbidden();
    }

    public function test_branch_scoping_refuses_another_branchs_student(): void
    {
        $nurseA = $this->makeUser('branch_admin', $this->branchA->id, ['medical-records-view']);

        $this->actingAs($nurseA)->get("/students/{$this->studentB->id}/medical")->assertForbidden();
    }

    public function test_medical_data_never_appears_in_the_ordinary_student_list(): void
    {
        $nurse = $this->makeUser('branch_admin', $this->branchA->id, ['medical-records-manage', 'manage-students']);
        $this->actingAs($nurse)->put("/students/{$this->studentA->id}/medical", ['allergies' => self::SENTINEL]);

        $response = $this->actingAs($nurse)->get('/students');
        $response->assertOk();
        $this->assertStringNotContainsString(self::SENTINEL, $response->getContent(), 'medical content must never reach the ordinary student list payload');
    }

    public function test_the_audit_trail_itself_never_contains_the_actual_medical_content(): void
    {
        $nurse = $this->makeUser('branch_admin', $this->branchA->id, ['medical-records-manage']);

        $this->actingAs($nurse)->put("/students/{$this->studentA->id}/medical", [
            'allergies' => self::SENTINEL, 'conditions' => 'Confidential-Condition-Sentinel', 'notes' => 'Secret-Note-Sentinel',
        ])->assertSessionHasNoErrors();

        $entry = AuditLog::where('action', 'student_medical_record.created')->firstOrFail();
        $raw = json_encode($entry->toArray());
        $this->assertStringNotContainsString(self::SENTINEL, $raw, 'the audit log is readable by anyone with view-audit-log, a BROADER permission -- it must never carry the content');
        $this->assertStringNotContainsString('Confidential-Condition-Sentinel', $raw);
        $this->assertStringNotContainsString('Secret-Note-Sentinel', $raw);
        $this->assertNull($entry->before);
        $this->assertNull($entry->after);

        // a second save is logged as 'updated', not a second 'created'
        $this->actingAs($nurse)->put("/students/{$this->studentA->id}/medical", ['allergies' => 'Changed']);
        $this->assertSame(1, AuditLog::where('action', 'student_medical_record.created')->count());
        $this->assertSame(1, AuditLog::where('action', 'student_medical_record.updated')->count());
    }

    public function test_saving_twice_updates_the_same_record_not_a_duplicate(): void
    {
        $nurse = $this->makeUser('branch_admin', $this->branchA->id, ['medical-records-manage']);

        $this->actingAs($nurse)->put("/students/{$this->studentA->id}/medical", ['blood_type' => 'A+']);
        $this->actingAs($nurse)->put("/students/{$this->studentA->id}/medical", ['blood_type' => 'B+']);

        $this->assertSame(1, StudentMedicalRecord::count());
        $this->assertSame('B+', StudentMedicalRecord::firstOrFail()->blood_type);
    }

    public function test_someone_with_no_permission_at_all_is_refused_both_routes(): void
    {
        $teacher = $this->makeUser('teacher', $this->branchA->id, []);

        $this->actingAs($teacher)->get("/students/{$this->studentA->id}/medical")->assertForbidden();
        $this->actingAs($teacher)->put("/students/{$this->studentA->id}/medical", ['allergies' => 'x'])->assertForbidden();
    }

    // ------------------------------------------------------------------ the dashboard/triage index and search
    // (a principal-facing overview, added so "who has something on file" doesn't need opening every profile)

    public function test_the_index_lists_only_students_with_a_record_and_flags_never_the_actual_content(): void
    {
        $nurse = $this->makeUser('branch_admin', $this->branchA->id, ['medical-records-manage']);
        $second = $this->makeStudent($this->branchA, $this->sectionA, 'Second', 'STU-A2');

        $this->actingAs($nurse)->put("/students/{$this->studentA->id}/medical", ['blood_type' => 'O+', 'allergies' => self::SENTINEL]);

        $page = $this->props($this->actingAs($nurse)->get('/medical-records')->assertOk());

        $this->assertCount(1, $page['students']);
        $row = $page['students'][0];
        $this->assertSame($this->studentA->id, $row['id']);
        $this->assertTrue($row['has_allergies']);
        $this->assertFalse($row['has_conditions']);
        $this->assertStringNotContainsString(self::SENTINEL, json_encode($page));
        $this->assertFalse(collect($page['students'])->pluck('id')->contains($second->id));
    }

    public function test_the_search_finds_a_student_in_scope_and_needs_manage_not_just_view(): void
    {
        $nurse = $this->makeUser('branch_admin', $this->branchA->id, ['medical-records-manage']);
        $viewer = $this->makeUser('branch_admin', $this->branchA->id, ['medical-records-view']);

        $found = $this->actingAs($nurse)->getJson('/medical-records/student-search?q='.substr($this->studentA->first_name, 0, 3))->assertOk()->json();
        $this->assertTrue(collect($found)->pluck('id')->contains($this->studentA->id));

        $this->actingAs($viewer)->getJson('/medical-records/student-search?q=te')->assertForbidden();
    }

    public function test_the_index_needs_view_or_manage_or_auditor(): void
    {
        $teacher = $this->makeUser('teacher', $this->branchA->id, []);
        $auditor = $this->makeUser('auditor', null, []);

        $this->actingAs($teacher)->get('/medical-records')->assertForbidden();
        $this->actingAs($auditor)->get('/medical-records')->assertOk();
    }

    public function test_the_dashboard_shows_an_on_file_count_only_to_someone_with_medical_access(): void
    {
        $nurse = $this->makeUser('branch_admin', $this->branchA->id, ['medical-records-manage']);
        $this->actingAs($nurse)->put("/students/{$this->studentA->id}/medical", ['blood_type' => 'O+']);

        $withAccess = $this->props($this->actingAs($nurse)->get('/dashboard')->assertOk())['stats']['medical'];
        $this->assertSame(1, $withAccess['on_file']);
        $this->assertGreaterThanOrEqual(1, $withAccess['total_enrolled']);

        $noAccess = $this->makeUser('branch_admin', $this->branchA->id, []);
        $this->assertNull($this->props($this->actingAs($noAccess)->get('/dashboard')->assertOk())['stats']['medical']);
    }
}
