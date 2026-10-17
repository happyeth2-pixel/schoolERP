<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\InventoryStore;
use App\Models\Requisition;
use App\Notifications\LowStockAlert;
use App\Notifications\RequisitionApproved;
use App\Notifications\RequisitionRejected;
use App\Notifications\RequisitionSubmitted;
use App\Services\Stock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

class RequisitionTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchool();
    }

    private function item(int $reorder = 0): InventoryItem
    {
        return InventoryItem::create(['school_id' => $this->school->id, 'name' => 'Exercise Book', 'unit' => 'pcs', 'min_stock_level' => 0, 'reorder_level' => $reorder]);
    }

    private function store(?int $branchId = null): InventoryStore
    {
        return InventoryStore::create(['school_id' => $this->school->id, 'branch_id' => $branchId, 'name' => 'Main Store']);
    }

    public function test_a_draft_can_be_built_then_submitted_later_and_notifies_approvers_in_scope(): void
    {
        $item = $this->item();
        $store = $this->store($this->branchA->id);
        $requester = $this->makeUser('teacher', $this->branchA->id, ['requisition-create']);
        $approverA = $this->makeUser('branch_admin', $this->branchA->id, ['requisition-approve']);
        $approverB = $this->makeUser('branch_admin', $this->branchB->id, ['requisition-approve']);

        $this->actingAs($requester)->post('/requisitions', [
            'store_id' => $store->id, 'purpose' => 'Classroom supplies', 'priority' => 'normal', 'action' => 'draft',
            'items' => [['item_id' => $item->id, 'quantity' => 20]],
        ])->assertSessionHasNoErrors();

        $requisition = Requisition::firstOrFail();
        $this->assertSame('draft', $requisition->status);
        $this->assertSame(0, $approverA->notifications()->count(), 'not visible to approvers until submitted');
        $this->assertStringStartsWith('REQ-', $requisition->number);

        $this->actingAs($requester)->post("/requisitions/{$requisition->id}/submit")->assertSessionHasNoErrors();
        $this->assertSame('submitted', $requisition->fresh()->status);
        $this->assertCount(1, $approverA->notifications, 'same branch as the store: notified');
        $this->assertSame(RequisitionSubmitted::class, $approverA->notifications->first()->type);
        $this->assertCount(0, $approverB->notifications, 'different branch: not notified');
    }

    public function test_creating_with_submit_action_submits_immediately(): void
    {
        $item = $this->item();
        $store = $this->store();
        $requester = $this->makeUser('teacher', null, ['requisition-create']);

        $this->actingAs($requester)->post('/requisitions', [
            'store_id' => $store->id, 'purpose' => 'Chalk', 'priority' => 'urgent', 'action' => 'submit',
            'items' => [['item_id' => $item->id, 'quantity' => 5]],
        ])->assertSessionHasNoErrors();

        $this->assertSame('submitted', Requisition::firstOrFail()->status);
    }

    public function test_approval_can_reduce_quantities_and_notifies_requester_and_stock_issuers(): void
    {
        $item = $this->item();
        $store = $this->store();
        $requester = $this->makeUser('teacher', null, ['requisition-create']);
        $approver = $this->makeUser('branch_admin', null, ['requisition-approve']);
        $issuer = $this->makeUser('accountant', null, ['stock-issue']);

        $this->actingAs($requester)->post('/requisitions', [
            'store_id' => $store->id, 'purpose' => 'Books', 'priority' => 'normal', 'action' => 'submit',
            'items' => [['item_id' => $item->id, 'quantity' => 30]],
        ]);
        $requisition = Requisition::firstOrFail();
        $line = $requisition->items->first();

        $this->actingAs($approver)->post("/requisitions/{$requisition->id}/approve", [
            'quantities' => [$line->id => 20],
        ])->assertSessionHasNoErrors();

        $requisition->refresh();
        $this->assertSame('approved', $requisition->status);
        $this->assertSame($approver->id, $requisition->reviewed_by);
        $this->assertSame(20, $requisition->items->first()->quantity_approved);
        $this->assertCount(1, $requester->notifications);
        $this->assertSame(RequisitionApproved::class, $requester->notifications->first()->type);
        $this->assertCount(1, $issuer->notifications, 'stock-issue holders are told to prepare it');
    }

    public function test_rejection_requires_a_reason_and_notifies_the_requester(): void
    {
        $item = $this->item();
        $store = $this->store();
        $requester = $this->makeUser('teacher', null, ['requisition-create']);
        $approver = $this->makeUser('branch_admin', null, ['requisition-approve']);

        $this->actingAs($requester)->post('/requisitions', [
            'store_id' => $store->id, 'purpose' => 'Books', 'priority' => 'normal', 'action' => 'submit',
            'items' => [['item_id' => $item->id, 'quantity' => 30]],
        ]);
        $requisition = Requisition::firstOrFail();

        $this->actingAs($approver)->post("/requisitions/{$requisition->id}/reject", [])->assertSessionHasErrors('reason');

        $this->actingAs($approver)->post("/requisitions/{$requisition->id}/reject", ['reason' => 'Not enough budget this term'])->assertSessionHasNoErrors();
        $requisition->refresh();
        $this->assertSame('rejected', $requisition->status);
        $this->assertSame('Not enough budget this term', $requisition->rejection_reason);
        $this->assertCount(1, $requester->notifications);
        $this->assertSame(RequisitionRejected::class, $requester->notifications->first()->type);
    }

    public function test_partial_then_full_issue_moves_stock_and_completes_the_requisition_automatically(): void
    {
        $item = $this->item();
        $store = $this->store();
        Stock::receive($item, $store, 100, $this->owner);
        $requester = $this->makeUser('teacher', null, ['requisition-create']);
        $approver = $this->makeUser('branch_admin', null, ['requisition-approve']);
        $issuer = $this->makeUser('accountant', null, ['stock-issue', 'requisition-issue']);

        $this->actingAs($requester)->post('/requisitions', [
            'store_id' => $store->id, 'purpose' => 'Books', 'priority' => 'normal', 'action' => 'submit',
            'items' => [['item_id' => $item->id, 'quantity' => 30]],
        ]);
        $requisition = Requisition::firstOrFail();
        $line = $requisition->items->first();
        $this->actingAs($approver)->post("/requisitions/{$requisition->id}/approve", ['quantities' => [$line->id => 30]]);

        $this->actingAs($issuer)->post("/requisitions/{$requisition->id}/issue", ['requisition_item_id' => $line->id, 'quantity' => 12])->assertSessionHasNoErrors();
        $requisition->refresh();
        $this->assertSame('partially_issued', $requisition->status);
        $this->assertSame(12, $requisition->items->first()->quantity_issued);
        $this->assertSame(88, Stock::balance($item, $store));

        $this->actingAs($issuer)->post("/requisitions/{$requisition->id}/issue", ['requisition_item_id' => $line->id, 'quantity' => 999])
            ->assertSessionHasErrors('quantity');
        $this->assertSame(12, $requisition->fresh()->items->first()->quantity_issued, 'the refused over-issue changed nothing');

        $this->actingAs($issuer)->post("/requisitions/{$requisition->id}/issue", ['requisition_item_id' => $line->id, 'quantity' => 18])->assertSessionHasNoErrors();
        $requisition->refresh();
        $this->assertSame('completed', $requisition->status, 'fully issued becomes completed automatically, no separate close step');
        $this->assertNotNull($requisition->completed_at);
        $this->assertSame(70, Stock::balance($item, $store));

        $movements = $item->movements()->where('related_requisition_item_id', $line->id)->get();
        $this->assertCount(2, $movements, 'both issues are traceable back to this requisition line');
    }

    public function test_each_action_needs_its_own_permission(): void
    {
        $item = $this->item();
        $store = $this->store();
        $bystander = $this->makeUser('teacher', null, []);

        $this->actingAs($bystander)->post('/requisitions', [
            'store_id' => $store->id, 'purpose' => 'x', 'priority' => 'normal', 'action' => 'draft',
            'items' => [['item_id' => $item->id, 'quantity' => 1]],
        ])->assertForbidden();

        $requester = $this->makeUser('teacher', null, ['requisition-create']);
        $this->actingAs($requester)->post('/requisitions', [
            'store_id' => $store->id, 'purpose' => 'x', 'priority' => 'normal', 'action' => 'submit',
            'items' => [['item_id' => $item->id, 'quantity' => 1]],
        ]);
        $requisition = Requisition::firstOrFail();

        $this->actingAs($bystander)->post("/requisitions/{$requisition->id}/approve", ['quantities' => [1]])->assertForbidden();
        // a requester without requisition-approve cannot approve their own requisition
        $this->actingAs($requester)->post("/requisitions/{$requisition->id}/approve", ['quantities' => [1]])->assertForbidden();
    }

    public function test_a_branch_admin_cannot_approve_a_requisition_against_another_branchs_store(): void
    {
        $item = $this->item();
        $storeB = $this->store($this->branchB->id);
        $requester = $this->makeUser('teacher', $this->branchB->id, ['requisition-create']);
        $approverA = $this->makeUser('branch_admin', $this->branchA->id, ['requisition-approve']);

        $this->actingAs($requester)->post('/requisitions', [
            'store_id' => $storeB->id, 'purpose' => 'x', 'priority' => 'normal', 'action' => 'submit',
            'items' => [['item_id' => $item->id, 'quantity' => 1]],
        ]);
        $requisition = Requisition::firstOrFail();

        $this->actingAs($approverA)->post("/requisitions/{$requisition->id}/approve", ['quantities' => [1]])->assertForbidden();
    }

    public function test_only_a_draft_can_be_removed_by_its_requester(): void
    {
        $item = $this->item();
        $store = $this->store();
        $requester = $this->makeUser('teacher', null, ['requisition-create']);

        $this->actingAs($requester)->post('/requisitions', [
            'store_id' => $store->id, 'purpose' => 'x', 'priority' => 'normal', 'action' => 'draft',
            'items' => [['item_id' => $item->id, 'quantity' => 1]],
        ]);
        $draft = Requisition::firstOrFail();
        $this->actingAs($requester)->delete("/requisitions/{$draft->id}")->assertSessionHasNoErrors();
        $this->assertNull(Requisition::find($draft->id));

        $this->actingAs($requester)->post('/requisitions', [
            'store_id' => $store->id, 'purpose' => 'x', 'priority' => 'normal', 'action' => 'submit',
            'items' => [['item_id' => $item->id, 'quantity' => 1]],
        ]);
        $submitted = Requisition::firstOrFail();
        $this->actingAs($submitted->requester)->delete("/requisitions/{$submitted->id}")->assertStatus(422);
        $this->assertNotNull(Requisition::find($submitted->id));
    }

    public function test_issuing_against_a_requisition_still_raises_the_low_stock_alert_once(): void
    {
        $item = $this->item(reorder: 10);
        $store = $this->store();
        Stock::receive($item, $store, 20, $this->owner);
        $watcher = $this->makeUser('branch_admin', null, ['inventory-manage']);
        $requester = $this->makeUser('teacher', null, ['requisition-create']);
        $approver = $this->makeUser('branch_admin', null, ['requisition-approve']);
        $issuer = $this->makeUser('accountant', null, ['requisition-issue']);

        $this->actingAs($requester)->post('/requisitions', [
            'store_id' => $store->id, 'purpose' => 'x', 'priority' => 'normal', 'action' => 'submit',
            'items' => [['item_id' => $item->id, 'quantity' => 15]],
        ]);
        $requisition = Requisition::firstOrFail();
        $line = $requisition->items->first();
        $this->actingAs($approver)->post("/requisitions/{$requisition->id}/approve", ['quantities' => [$line->id => 15]]);

        $this->actingAs($issuer)->post("/requisitions/{$requisition->id}/issue", ['requisition_item_id' => $line->id, 'quantity' => 15]);

        $this->assertCount(1, $watcher->notifications);
        $this->assertSame(LowStockAlert::class, $watcher->notifications->first()->type);
    }
}
