<?php

namespace Tests\Feature;

use App\Models\PurchaseRequest;
use App\Models\Supplier;
use App\Notifications\PurchaseRequestApproved;
use App\Notifications\PurchaseRequestRejected;
use App\Notifications\PurchaseRequestSubmitted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

class ProcurementTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchool();
    }

    // ------------------------------------------------------------------ suppliers

    public function test_only_supplier_manage_can_create_a_supplier_and_it_gets_an_auto_generated_code(): void
    {
        $viewer = $this->makeUser('teacher', null, []);
        $manager = $this->makeUser('branch_admin', null, ['supplier-manage']);

        $this->actingAs($viewer)->post('/suppliers', ['name' => 'ABC Stationery'])->assertForbidden();
        $this->actingAs($manager)->post('/suppliers', ['name' => 'ABC Stationery', 'phone' => '0911000000'])->assertSessionHasNoErrors();

        $supplier = Supplier::firstOrFail();
        $this->assertStringStartsWith('SUP-', $supplier->code);
        $this->assertSame('ABC Stationery', $supplier->name);
    }

    public function test_removing_a_supplier_archives_it_rather_than_deleting_it(): void
    {
        $manager = $this->makeUser('branch_admin', null, ['supplier-manage']);
        $this->actingAs($manager)->post('/suppliers', ['name' => 'ABC Stationery']);
        $supplier = Supplier::firstOrFail();

        $this->actingAs($manager)->delete("/suppliers/{$supplier->id}")->assertSessionHasNoErrors();
        $this->assertNull(Supplier::find($supplier->id));
        $this->assertNotNull(Supplier::withTrashed()->find($supplier->id), 'archived, not erased');
    }

    // ------------------------------------------------------------------ purchase requests: draft/submit/notify

    public function test_a_draft_can_be_built_then_submitted_later_and_notifies_approvers_in_scope(): void
    {
        $requester = $this->makeUser('teacher', $this->branchA->id, ['purchase-request-create']);
        $approverA = $this->makeUser('branch_admin', $this->branchA->id, ['purchase-approve']);
        $approverB = $this->makeUser('branch_admin', $this->branchB->id, ['purchase-approve']);

        $this->actingAs($requester)->post('/purchase-requests', [
            'purpose' => 'Classroom paint', 'priority' => 'normal', 'action' => 'draft',
            'items' => [['description' => 'White paint, 4L', 'quantity' => 3, 'estimated_unit_price' => 500]],
        ])->assertSessionHasNoErrors();

        $pr = PurchaseRequest::firstOrFail();
        $this->assertSame('draft', $pr->status);
        $this->assertSame(1500.0, $pr->estimatedTotal());
        $this->assertSame(0, $approverA->notifications()->count(), 'not visible to approvers until submitted');

        $this->actingAs($requester)->post("/purchase-requests/{$pr->id}/submit")->assertSessionHasNoErrors();
        $this->assertSame('submitted', $pr->fresh()->status);
        $this->assertSame(1, $approverA->notifications()->count(), 'same branch: notified');
        $this->assertSame(PurchaseRequestSubmitted::class, $approverA->notifications()->first()->type);
        $this->assertSame(0, $approverB->notifications()->count(), 'different branch: not notified');
    }

    public function test_a_line_may_be_a_free_text_service_with_no_catalog_item(): void
    {
        $requester = $this->makeUser('teacher', null, ['purchase-request-create']);

        $this->actingAs($requester)->post('/purchase-requests', [
            'purpose' => 'Photocopier maintenance', 'priority' => 'normal', 'action' => 'submit',
            'items' => [['description' => 'Annual photocopier service contract', 'quantity' => 1, 'estimated_unit_price' => 2000]],
        ])->assertSessionHasNoErrors();

        $line = PurchaseRequest::firstOrFail()->items->first();
        $this->assertNull($line->item_id);
        $this->assertSame('Annual photocopier service contract', $line->description);
    }

    // ------------------------------------------------------------------ approval, rejection, threshold

    public function test_approving_and_rejecting_notify_the_requester(): void
    {
        $requester = $this->makeUser('teacher', null, ['purchase-request-create']);
        $approver = $this->makeUser('branch_admin', null, ['purchase-approve']);

        $this->actingAs($requester)->post('/purchase-requests', [
            'purpose' => 'Books', 'priority' => 'normal', 'action' => 'submit',
            'items' => [['description' => 'Library books', 'quantity' => 10, 'estimated_unit_price' => 100]],
        ]);
        $pr = PurchaseRequest::firstOrFail();

        $this->actingAs($approver)->post("/purchase-requests/{$pr->id}/approve")->assertSessionHasNoErrors();
        $pr->refresh();
        $this->assertSame('approved', $pr->status);
        $this->assertSame($approver->id, $pr->reviewed_by);
        $this->assertCount(1, $requester->notifications);
        $this->assertSame(PurchaseRequestApproved::class, $requester->notifications->first()->type);

        // a second one, rejected
        $this->actingAs($requester)->post('/purchase-requests', [
            'purpose' => 'More books', 'priority' => 'normal', 'action' => 'submit',
            'items' => [['description' => 'More library books', 'quantity' => 5, 'estimated_unit_price' => 100]],
        ]);
        $pr2 = PurchaseRequest::latest('id')->first();
        $this->actingAs($approver)->post("/purchase-requests/{$pr2->id}/reject", [])->assertSessionHasErrors('reason');
        $this->actingAs($approver)->post("/purchase-requests/{$pr2->id}/reject", ['reason' => 'Budget exhausted this term'])->assertSessionHasNoErrors();
        $pr2->refresh();
        $this->assertSame('rejected', $pr2->status);
        $this->assertSame('Budget exhausted this term', $pr2->rejection_reason);
        $this->assertCount(1, $requester->notifications()->where('type', PurchaseRequestRejected::class)->get());
    }

    public function test_a_requester_can_never_approve_their_own_request_even_if_they_hold_the_permission(): void
    {
        $requester = $this->makeUser('branch_admin', null, ['purchase-request-create', 'purchase-approve']);

        $this->actingAs($requester)->post('/purchase-requests', [
            'purpose' => 'Books', 'priority' => 'normal', 'action' => 'submit',
            'items' => [['description' => 'Books', 'quantity' => 1, 'estimated_unit_price' => 100]],
        ]);
        $pr = PurchaseRequest::firstOrFail();

        $this->actingAs($requester)->post("/purchase-requests/{$pr->id}/approve")->assertForbidden();
        $this->actingAs($requester)->post("/purchase-requests/{$pr->id}/reject", ['reason' => 'no'])->assertForbidden();
    }

    /**
     * The approve/reject buttons on the request's own page must agree with what approve()/reject()
     * actually allow -- a requester who also holds purchase-approve must never be shown a button that
     * can only 403 the moment it's pressed (exactly what the "the buttons don't work" bug looked like).
     */
    public function test_the_shown_page_never_offers_approve_or_reject_to_the_requester_themselves(): void
    {
        $requester = $this->makeUser('branch_admin', null, ['purchase-request-create', 'purchase-approve']);
        $otherApprover = $this->makeUser('branch_admin', null, ['purchase-approve']);

        $this->actingAs($requester)->post('/purchase-requests', [
            'purpose' => 'Books', 'priority' => 'normal', 'action' => 'submit',
            'items' => [['description' => 'Books', 'quantity' => 1, 'estimated_unit_price' => 100]],
        ]);
        $pr = PurchaseRequest::firstOrFail();

        $ownPage = $this->props($this->actingAs($requester)->get("/purchase-requests/{$pr->id}")->assertOk());
        $this->assertFalse($ownPage['canApprove'], 'a requester who also approves must never see the button on their own request');

        $othersPage = $this->props($this->actingAs($otherApprover)->get("/purchase-requests/{$pr->id}")->assertOk());
        $this->assertTrue($othersPage['canApprove']);
    }

    public function test_above_the_configured_threshold_needs_the_large_approval_permission_instead(): void
    {
        $this->school->update(['purchase_approval_threshold' => 1000]);
        $requester = $this->makeUser('teacher', null, ['purchase-request-create']);
        $smallApprover = $this->makeUser('branch_admin', null, ['purchase-approve']);
        $largeApprover = $this->makeUser('branch_admin', null, ['purchase-approve-large']);

        $this->actingAs($requester)->post('/purchase-requests', [
            'purpose' => 'Big order', 'priority' => 'normal', 'action' => 'submit',
            'items' => [['description' => 'Projectors', 'quantity' => 5, 'estimated_unit_price' => 500]], // 2500 > 1000
        ]);
        $pr = PurchaseRequest::firstOrFail();
        $this->assertTrue($pr->needsLargeApproval());

        $this->actingAs($smallApprover)->post("/purchase-requests/{$pr->id}/approve")->assertForbidden();
        $this->actingAs($largeApprover)->post("/purchase-requests/{$pr->id}/approve")->assertSessionHasNoErrors();
        $this->assertSame('approved', $pr->fresh()->status);
    }

    public function test_the_threshold_can_be_set_and_cleared_from_school_settings(): void
    {
        $this->actingAs($this->owner)->patch('/settings/school', $this->schoolSettingsPayload(['purchase_approval_threshold' => 5000]))->assertSessionHasNoErrors();
        $this->assertEquals(5000, $this->school->fresh()->purchase_approval_threshold);

        $this->actingAs($this->owner)->patch('/settings/school', $this->schoolSettingsPayload(['purchase_approval_threshold' => '']))->assertSessionHasNoErrors();
        $this->assertNull($this->school->fresh()->purchase_approval_threshold);
    }

    private function schoolSettingsPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => $this->school->name,
            'calendar_preference' => 'both',
            'missing_mark_policy' => 'exclude',
            'discount_limit_percent' => 10,
            'waiver_limit_amount' => 1000,
            'payment_due_days' => 14,
            'allow_overlapping_terms' => false,
            'enabled_languages' => ['en'],
        ], $overrides);
    }

    public function test_with_no_threshold_configured_the_ordinary_approval_permission_is_always_enough(): void
    {
        $this->assertNull($this->school->purchase_approval_threshold);
        $requester = $this->makeUser('teacher', null, ['purchase-request-create']);
        $approver = $this->makeUser('branch_admin', null, ['purchase-approve']);

        $this->actingAs($requester)->post('/purchase-requests', [
            'purpose' => 'Expensive order', 'priority' => 'normal', 'action' => 'submit',
            'items' => [['description' => 'Equipment', 'quantity' => 1, 'estimated_unit_price' => 1000000]],
        ]);
        $pr = PurchaseRequest::firstOrFail();

        $this->actingAs($approver)->post("/purchase-requests/{$pr->id}/approve")->assertSessionHasNoErrors();
    }

    // ------------------------------------------------------------------ branch isolation, drafts, permissions

    public function test_a_branch_admin_cannot_approve_a_request_from_another_branch(): void
    {
        $requester = $this->makeUser('teacher', $this->branchB->id, ['purchase-request-create']);
        $approverA = $this->makeUser('branch_admin', $this->branchA->id, ['purchase-approve']);

        $this->actingAs($requester)->post('/purchase-requests', [
            'purpose' => 'Books', 'priority' => 'normal', 'action' => 'submit',
            'items' => [['description' => 'Books', 'quantity' => 1, 'estimated_unit_price' => 10]],
        ]);
        $pr = PurchaseRequest::firstOrFail();

        $this->actingAs($approverA)->post("/purchase-requests/{$pr->id}/approve")->assertForbidden();
    }

    public function test_only_a_draft_can_be_removed_by_its_requester(): void
    {
        $requester = $this->makeUser('teacher', null, ['purchase-request-create']);

        $this->actingAs($requester)->post('/purchase-requests', [
            'purpose' => 'x', 'priority' => 'normal', 'action' => 'draft',
            'items' => [['description' => 'x', 'quantity' => 1]],
        ]);
        $draft = PurchaseRequest::firstOrFail();
        $this->actingAs($requester)->delete("/purchase-requests/{$draft->id}")->assertSessionHasNoErrors();
        $this->assertNull(PurchaseRequest::find($draft->id));

        $this->actingAs($requester)->post('/purchase-requests', [
            'purpose' => 'x', 'priority' => 'normal', 'action' => 'submit',
            'items' => [['description' => 'x', 'quantity' => 1]],
        ]);
        $submitted = PurchaseRequest::firstOrFail();
        $this->actingAs($requester)->delete("/purchase-requests/{$submitted->id}")->assertStatus(422);
        $this->assertNotNull(PurchaseRequest::find($submitted->id));
    }

    public function test_purchase_request_create_is_required_to_open_the_wizard_or_submit_one(): void
    {
        $bystander = $this->makeUser('teacher', null, []);

        $this->actingAs($bystander)->post('/purchase-requests', [
            'purpose' => 'x', 'priority' => 'normal', 'action' => 'draft',
            'items' => [['description' => 'x', 'quantity' => 1]],
        ])->assertForbidden();
    }
}
