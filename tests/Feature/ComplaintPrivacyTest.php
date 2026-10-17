<?php

namespace Tests\Feature;

use App\Models\Complaint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

/** Handling a complaint does not, by itself, open the child's or the guardian's records. */
class ComplaintPrivacyTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    private Complaint $complaint;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchool();
        $parent = $this->makeParent($this->studentA, $this->makeStudent($this->branchA, $this->sectionA, 'Sibling', 'STU-S'));
        $parent->update(['phone' => '0911223344']);
        $this->complaint = Complaint::create(['school_id' => $this->school->id, 'branch_id' => $this->branchA->id, 'submitted_by' => $parent->id, 'student_id' => $this->studentA->id, 'subject' => 'Bus late', 'body' => 'Every day']);
    }

    private function show(\App\Models\User $u): array
    {
        return $this->props($this->actingAs($u)->get("/complaints/{$this->complaint->id}"))['complaint'];
    }

    public function test_a_complaint_handler_without_student_access_sees_the_complaint_but_not_the_child_or_contact(): void
    {
        $handler = $this->makeUser('branch_admin', $this->branchA->id, ['manage-complaints']);

        $c = $this->show($handler);

        $this->assertSame('Bus late', $c['subject']);
        $this->assertNotNull($c['submitted_by']);
        $this->assertTrue($c['student']['restricted']);
        $this->assertNull($c['student']['name']);
        $this->assertNull($c['student']['section']);
        $this->assertSame([], collect($c['siblings'])->all(), 'siblings are student records too');
        $this->assertNull($c['submitted_by_email']);
        $this->assertNull($c['submitted_by_phone']);
        $this->assertTrue($c['contact_restricted']);
        $this->assertStringNotContainsString('Almaz', json_encode($c));
    }

    public function test_the_list_also_hides_the_child_from_such_a_handler(): void
    {
        $handler = $this->makeUser('branch_admin', $this->branchA->id, ['manage-complaints']);

        $row = $this->props($this->actingAs($handler)->get('/complaints'))['complaints'][0];

        $this->assertTrue($row['student']['restricted']);
        $this->assertStringNotContainsString('Almaz', json_encode($row));
    }

    public function test_student_records_permission_reveals_the_child_and_siblings_but_not_contact(): void
    {
        $handler = $this->makeUser('branch_admin', $this->branchA->id, ['manage-complaints', 'manage-students']);

        $c = $this->show($handler);

        $this->assertFalse($c['student']['restricted']);
        $this->assertStringContainsString('Almaz', $c['student']['name']);
        $this->assertSame('G1 A', $c['student']['section']);
        $this->assertCount(1, $c['siblings']);
        $this->assertNull($c['submitted_by_email']);
    }

    public function test_parent_records_permission_reveals_contact_but_not_the_child(): void
    {
        $handler = $this->makeUser('branch_admin', $this->branchA->id, ['manage-complaints', 'manage-parents']);

        $c = $this->show($handler);

        $this->assertTrue($c['student']['restricted']);
        $this->assertNotNull($c['submitted_by_email']);
        $this->assertSame('0911 22 33 44', $c['submitted_by_phone']);
    }

    public function test_owner_and_auditor_see_everything_and_the_filer_sees_their_own(): void
    {
        foreach ([$this->owner, $this->makeUser('auditor', null)] as $u) {
            $c = $this->show($u);
            $this->assertFalse($c['student']['restricted'], $u->name);
            $this->assertNotNull($c['submitted_by_email'], $u->name);
        }

        $filer = $this->complaint->submittedBy;
        $c = $this->props($this->actingAs($filer)->get("/complaints/{$this->complaint->id}"))['complaint'];
        $this->assertFalse($c['student']['restricted']);
        $this->assertSame([], collect($c['siblings'])->all(), 'the filer is not shown a sibling list about themselves');
    }

    public function test_a_handler_in_another_branch_cannot_open_it_at_all_and_student_access_is_branch_scoped(): void
    {
        $other = $this->makeUser('branch_admin', $this->branchB->id, ['manage-complaints', 'manage-students', 'manage-parents']);
        $this->actingAs($other)->get("/complaints/{$this->complaint->id}")->assertForbidden();

        // a handler with student access only in ANOTHER branch than the child's sees the complaint (it is theirs) but not the child
        $this->complaint->update(['branch_id' => $this->branchB->id]);
        $c = $this->show($other);
        $this->assertTrue($c['student']['restricted'], 'the child is in branch A; this account manages students in branch B');
    }
}
