<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\DisciplineIncident;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

/**
 * Academic expansion, Phase 7: Student Discipline. "incident/category/action/status/attachments under
 * permission protection" (the brief's own words). The most important test here, same as Medical Records:
 * the audit trail itself must never leak the actual incident content, since `view-audit-log` is a BROADER
 * permission than `discipline-view`/`manage`.
 */
class DisciplineIncidentTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    private const SENTINEL = 'Severe-Cheating-Incident-Sentinel-XYZ';

    private const PLAIN_PDF = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF";

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchool();
        Storage::fake('local');
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'category' => 'cheating', 'description' => self::SENTINEL, 'incident_date' => '2026-10-10',
            'action_taken' => 'verbal_warning', 'status' => 'open',
        ], $over);
    }

    public function test_manage_students_alone_does_not_grant_access(): void
    {
        $registrar = $this->makeUser('branch_admin', $this->branchA->id, ['manage-students']);

        $this->actingAs($registrar)->get("/students/{$this->studentA->id}/discipline")->assertForbidden();
        $this->actingAs($registrar)->post("/students/{$this->studentA->id}/discipline", $this->payload())->assertForbidden();
    }

    public function test_view_permission_can_see_but_not_write(): void
    {
        $viewer = $this->makeUser('branch_admin', $this->branchA->id, ['discipline-view']);

        $this->actingAs($viewer)->get("/students/{$this->studentA->id}/discipline")->assertOk();
        $this->actingAs($viewer)->post("/students/{$this->studentA->id}/discipline", $this->payload())->assertForbidden();
        $this->assertSame(0, DisciplineIncident::count());
    }

    public function test_manage_permission_can_record_and_see_it(): void
    {
        $officer = $this->makeUser('branch_admin', $this->branchA->id, ['discipline-manage']);

        $this->actingAs($officer)->post("/students/{$this->studentA->id}/discipline", $this->payload())->assertSessionHasNoErrors();

        $incident = DisciplineIncident::firstOrFail();
        $this->assertSame($this->studentA->id, $incident->student_id);
        $this->assertSame(self::SENTINEL, $incident->description);
        $this->assertSame($officer->id, $incident->reported_by);

        $p = $this->props($this->actingAs($officer)->get("/students/{$this->studentA->id}/discipline"));
        $this->assertSame(self::SENTINEL, $p['incidents'][0]['description']);
    }

    public function test_an_auditor_can_view_read_only_regardless_of_explicit_permission(): void
    {
        $auditor = $this->makeUser('auditor', null, []);

        $this->actingAs($auditor)->get("/students/{$this->studentA->id}/discipline")->assertOk();
        $this->actingAs($auditor)->get('/discipline')->assertOk();
        $this->actingAs($auditor)->post("/students/{$this->studentA->id}/discipline", $this->payload())->assertForbidden();
    }

    public function test_branch_scoping_refuses_another_branchs_student(): void
    {
        $officerA = $this->makeUser('branch_admin', $this->branchA->id, ['discipline-view']);

        $this->actingAs($officerA)->get("/students/{$this->studentB->id}/discipline")->assertForbidden();
    }

    public function test_discipline_data_never_appears_in_the_ordinary_student_list(): void
    {
        $officer = $this->makeUser('branch_admin', $this->branchA->id, ['discipline-manage', 'manage-students']);
        $this->actingAs($officer)->post("/students/{$this->studentA->id}/discipline", $this->payload());

        $response = $this->actingAs($officer)->get('/students');
        $response->assertOk();
        $this->assertStringNotContainsString(self::SENTINEL, $response->getContent());
    }

    public function test_the_audit_trail_never_contains_the_actual_incident_content(): void
    {
        $officer = $this->makeUser('branch_admin', $this->branchA->id, ['discipline-manage']);

        $this->actingAs($officer)->post("/students/{$this->studentA->id}/discipline", $this->payload([
            'description' => self::SENTINEL,
        ]))->assertSessionHasNoErrors();

        $entry = AuditLog::where('action', 'discipline_incident.created')->firstOrFail();
        $raw = json_encode($entry->toArray());
        $this->assertStringNotContainsString(self::SENTINEL, $raw);
        $this->assertNull($entry->before);
        $this->assertNull($entry->after);
    }

    public function test_resolving_an_incident_records_who_and_when(): void
    {
        $officer = $this->makeUser('branch_admin', $this->branchA->id, ['discipline-manage']);
        $this->actingAs($officer)->post("/students/{$this->studentA->id}/discipline", $this->payload(['status' => 'open']));
        $incident = DisciplineIncident::firstOrFail();

        $this->actingAs($officer)->put("/discipline/{$incident->id}", $this->payload(['status' => 'resolved']))->assertSessionHasNoErrors();

        $incident->refresh();
        $this->assertSame('resolved', $incident->status);
        $this->assertSame($officer->id, $incident->resolved_by);
        $this->assertNotNull($incident->resolved_at);
    }

    public function test_an_attachment_can_be_uploaded_and_only_an_authorized_viewer_can_open_it(): void
    {
        $officer = $this->makeUser('branch_admin', $this->branchA->id, ['discipline-manage']);
        $this->actingAs($officer)->post("/students/{$this->studentA->id}/discipline", $this->payload([
            'attachment' => UploadedFile::fake()->createWithContent('evidence.pdf', self::PLAIN_PDF),
        ]));
        $incident = DisciplineIncident::firstOrFail();
        $this->assertNotNull($incident->attachment_path);

        $this->actingAs($officer)->get("/discipline/{$incident->id}/document")->assertOk();

        $outsider = $this->makeUser('branch_admin', $this->branchB->id, ['discipline-view']);
        $this->actingAs($outsider)->get("/discipline/{$incident->id}/document")->assertForbidden();

        $teacher = $this->makeUser('teacher', $this->branchA->id, []);
        $this->actingAs($teacher)->get("/discipline/{$incident->id}/document")->assertForbidden();
    }

    public function test_there_is_no_way_to_delete_an_incident(): void
    {
        $officer = $this->makeUser('branch_admin', $this->branchA->id, ['discipline-manage']);
        $this->actingAs($officer)->post("/students/{$this->studentA->id}/discipline", $this->payload());
        $incident = DisciplineIncident::firstOrFail();

        $this->actingAs($officer)->delete("/discipline/{$incident->id}")->assertStatus(405);
        $this->assertSame(1, DisciplineIncident::count());
    }

    public function test_someone_with_no_permission_at_all_is_refused_everything(): void
    {
        $teacher = $this->makeUser('teacher', $this->branchA->id, []);

        $this->actingAs($teacher)->get("/students/{$this->studentA->id}/discipline")->assertForbidden();
        $this->actingAs($teacher)->get('/discipline')->assertForbidden();
        $this->actingAs($teacher)->post("/students/{$this->studentA->id}/discipline", $this->payload())->assertForbidden();
    }

    // ------------------------------------------------------------------ "report an incident" search (a quick way
    // in from the triage dashboard, without first opening the student's own profile)

    public function test_the_search_finds_a_student_by_first_or_father_name_in_scope(): void
    {
        $officer = $this->makeUser('branch_admin', $this->branchA->id, ['discipline-manage']);

        $byFirst = $this->actingAs($officer)->getJson('/discipline/student-search?q='.substr($this->studentA->first_name, 0, 3))->assertOk()->json();
        $this->assertTrue(collect($byFirst)->pluck('id')->contains($this->studentA->id));

        $outOfBranch = $this->actingAs($officer)->getJson('/discipline/student-search?q='.substr($this->studentB->first_name, 0, 3))->assertOk()->json();
        $this->assertFalse(collect($outOfBranch)->pluck('id')->contains($this->studentB->id));
    }

    public function test_the_search_needs_discipline_manage_not_just_view(): void
    {
        $viewer = $this->makeUser('branch_admin', $this->branchA->id, ['discipline-view']);
        $this->actingAs($viewer)->getJson('/discipline/student-search?q=te')->assertForbidden();
    }

    public function test_the_search_index_page_only_offers_it_to_someone_who_can_manage(): void
    {
        $manager = $this->makeUser('branch_admin', $this->branchA->id, ['discipline-manage']);
        $viewer = $this->makeUser('branch_admin', $this->branchA->id, ['discipline-view']);

        $this->assertTrue($this->props($this->actingAs($manager)->get('/discipline')->assertOk())['canManage']);
        $this->assertFalse($this->props($this->actingAs($viewer)->get('/discipline')->assertOk())['canManage']);
    }
}
