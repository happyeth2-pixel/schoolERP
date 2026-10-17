<?php

namespace Tests\Feature;

use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

/**
 * §3 hardening: Procurement had no dashboard presence at all, unlike its sibling Inventory/Requisitions
 * (DashboardController::requisitionStats(), which this mirrors exactly -- same "null = not for you"
 * convention, same shape). Counts respect the large-purchase approval threshold split: a plain
 * `purchase-approve` holder must never be shown (or able to act on) a request that actually needs
 * `purchase-approve-large`, and vice versa.
 */
class ProcurementDashboardTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchool();
    }

    private function procurement(User $user): ?array
    {
        return $this->props($this->actingAs($user)->get('/dashboard')->assertOk())['stats']['procurement'] ?? null;
    }

    private function request(User $requester, ?int $branchId, float $estimatedUnitPrice, string $status = 'submitted'): PurchaseRequest
    {
        $pr = PurchaseRequest::create([
            'school_id' => $this->school->id, 'branch_id' => $branchId, 'requester_id' => $requester->id,
            'number' => 'PR-'.uniqid(), 'purpose' => 'Supplies', 'priority' => 'normal', 'status' => $status,
        ]);
        PurchaseRequestItem::create(['purchase_request_id' => $pr->id, 'description' => 'Item', 'quantity' => 1, 'estimated_unit_price' => $estimatedUnitPrice, 'estimated_total' => $estimatedUnitPrice]);

        return $pr;
    }

    public function test_someone_with_no_procurement_permission_gets_no_block_at_all(): void
    {
        $bystander = $this->makeUser('teacher', $this->branchA->id, []);
        $this->assertNull($this->procurement($bystander));
    }

    public function test_a_plain_approver_sees_ordinary_requests_but_not_ones_needing_large_approval(): void
    {
        $approver = $this->makeUser('branch_admin', $this->branchA->id, ['purchase-approve']);
        $this->school->update(['purchase_approval_threshold' => 1000]);
        $requester = $this->makeUser('teacher', $this->branchA->id, ['purchase-request-create']);

        $this->request($requester, $this->branchA->id, 500);  // ordinary
        $this->request($requester, $this->branchA->id, 5000); // needs purchase-approve-large

        $stats = $this->procurement($approver);
        $this->assertSame(1, $stats['pending_approvals'], 'the large one must not be counted for a plain approver');
    }

    public function test_a_large_approver_sees_only_the_large_ones(): void
    {
        $largeApprover = $this->makeUser('branch_admin', $this->branchA->id, ['purchase-approve-large']);
        $this->school->update(['purchase_approval_threshold' => 1000]);
        $requester = $this->makeUser('teacher', $this->branchA->id, ['purchase-request-create']);

        $this->request($requester, $this->branchA->id, 500);  // ordinary -- not theirs to approve
        $this->request($requester, $this->branchA->id, 5000); // large -- theirs

        $stats = $this->procurement($largeApprover);
        $this->assertSame(1, $stats['pending_approvals']);
    }

    public function test_only_submitted_status_counts_not_draft_or_approved(): void
    {
        $approver = $this->makeUser('branch_admin', $this->branchA->id, ['purchase-approve']);
        $requester = $this->makeUser('teacher', $this->branchA->id, ['purchase-request-create']);

        $this->request($requester, $this->branchA->id, 100, 'submitted');
        $this->request($requester, $this->branchA->id, 100, 'draft');
        $this->request($requester, $this->branchA->id, 100, 'approved');

        $this->assertSame(1, $this->procurement($approver)['pending_approvals']);
    }

    public function test_a_branch_scoped_approver_never_sees_another_branchs_requests(): void
    {
        $approverA = $this->makeUser('branch_admin', $this->branchA->id, ['purchase-approve']);
        $requesterB = $this->makeUser('teacher', $this->branchB->id, ['purchase-request-create']);
        $this->request($requesterB, $this->branchB->id, 100);

        $this->assertSame(0, $this->procurement($approverA)['pending_approvals']);
    }

    public function test_an_org_wide_owner_sees_every_branch(): void
    {
        $requesterA = $this->makeUser('teacher', $this->branchA->id, ['purchase-request-create']);
        $requesterB = $this->makeUser('teacher', $this->branchB->id, ['purchase-request-create']);
        $this->request($requesterA, $this->branchA->id, 100);
        $this->request($requesterB, $this->branchB->id, 100);

        $this->assertSame(2, $this->procurement($this->owner)['pending_approvals']);
    }

    public function test_my_pending_counts_the_requesters_own_submitted_and_approved_requests(): void
    {
        // a `branch_admin`, not a `teacher` -- only owner/branch_admin/auditor roles route through
        // orgStats() (where this block lives) in the first place, the same pre-existing limitation
        // requisitionStats() already has; holding purchase-request-create alone, with neither approve
        // permission, still represents a genuine "requester only" account.
        $requester = $this->makeUser('branch_admin', $this->branchA->id, ['purchase-request-create']);
        $this->request($requester, $this->branchA->id, 100, 'submitted');
        $this->request($requester, $this->branchA->id, 100, 'approved');
        $this->request($requester, $this->branchA->id, 100, 'rejected');

        $stats = $this->procurement($requester);
        $this->assertSame(2, $stats['my_pending']);
        $this->assertNull($stats['pending_approvals'], 'a plain requester holds no approval permission at all');
    }
}
