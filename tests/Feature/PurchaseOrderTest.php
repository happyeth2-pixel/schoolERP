<?php

namespace Tests\Feature;

use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Quotation;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

class PurchaseOrderTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchool();
    }

    private function approvedRequest(int $quantity = 10): PurchaseRequest
    {
        $requester = $this->makeUser('teacher', null, ['purchase-request-create']);
        $this->actingAs($requester)->post('/purchase-requests', [
            'purpose' => 'Books', 'priority' => 'normal', 'action' => 'submit',
            'items' => [['description' => 'Library books', 'quantity' => $quantity, 'estimated_unit_price' => 100]],
        ]);
        $pr = PurchaseRequest::firstOrFail();
        $this->actingAs($this->owner)->post("/purchase-requests/{$pr->id}/approve");

        return $pr->fresh();
    }

    private function supplier(string $name = 'ABC Stationery'): Supplier
    {
        return Supplier::create(['school_id' => $this->school->id, 'code' => 'SUP-'.uniqid(), 'name' => $name]);
    }

    public function test_a_purchase_order_can_only_be_created_against_an_approved_request(): void
    {
        $requester = $this->makeUser('teacher', null, ['purchase-request-create']);
        $this->actingAs($requester)->post('/purchase-requests', [
            'purpose' => 'Books', 'priority' => 'normal', 'action' => 'draft',
            'items' => [['description' => 'Books', 'quantity' => 1, 'estimated_unit_price' => 10]],
        ]);
        $draft = PurchaseRequest::firstOrFail();
        $line = $draft->items->first();
        $supplier = $this->supplier();
        $manager = $this->makeUser('branch_admin', null, ['purchase-order-manage']);

        $this->actingAs($manager)->post("/purchase-requests/{$draft->id}/purchase-orders", [
            'supplier_id' => $supplier->id, 'order_date' => now()->toDateString(),
            'items' => [['purchase_request_item_id' => $line->id, 'quantity' => 1, 'unit_price' => 10]],
        ])->assertStatus(422);
        $this->assertSame(0, PurchaseOrder::count());
    }

    public function test_creating_a_purchase_order_computes_line_and_grand_totals_and_gets_a_po_number(): void
    {
        $pr = $this->approvedRequest();
        $line = $pr->items->first();
        $supplier = $this->supplier();
        $manager = $this->makeUser('branch_admin', null, ['purchase-order-manage']);

        $this->actingAs($manager)->post("/purchase-requests/{$pr->id}/purchase-orders", [
            'supplier_id' => $supplier->id, 'order_date' => now()->toDateString(),
            'items' => [['purchase_request_item_id' => $line->id, 'quantity' => 10, 'unit_price' => 95, 'tax_amount' => 50]],
        ])->assertSessionHasNoErrors();

        $po = PurchaseOrder::firstOrFail();
        $this->assertSame($supplier->id, $po->supplier_id);
        $this->assertSame('draft', $po->status);
        $this->assertStringStartsWith('PO-', $po->number);
        $this->assertSame(1000.0, $po->total(), '10 * 95 + 50 = 1000');
    }

    public function test_a_purchase_order_cannot_order_more_than_the_request_line_asked_for(): void
    {
        $pr = $this->approvedRequest(quantity: 10);
        $line = $pr->items->first();
        $supplier = $this->supplier();
        $manager = $this->makeUser('branch_admin', null, ['purchase-order-manage']);

        $this->actingAs($manager)->post("/purchase-requests/{$pr->id}/purchase-orders", [
            'supplier_id' => $supplier->id, 'order_date' => now()->toDateString(),
            'items' => [['purchase_request_item_id' => $line->id, 'quantity' => 11, 'unit_price' => 100]],
        ])->assertSessionHasErrors('items.0.quantity');
        $this->assertSame(0, PurchaseOrder::count());
    }

    public function test_a_request_line_can_be_split_across_two_purchase_orders_but_never_over_ordered(): void
    {
        $pr = $this->approvedRequest(quantity: 10);
        $line = $pr->items->first();
        $supplierA = $this->supplier('Supplier A');
        $supplierB = $this->supplier('Supplier B');
        $manager = $this->makeUser('branch_admin', null, ['purchase-order-manage']);

        $this->actingAs($manager)->post("/purchase-requests/{$pr->id}/purchase-orders", [
            'supplier_id' => $supplierA->id, 'order_date' => now()->toDateString(),
            'items' => [['purchase_request_item_id' => $line->id, 'quantity' => 6, 'unit_price' => 100]],
        ])->assertSessionHasNoErrors();

        // 6 already ordered, only 4 left -- ordering 5 more from a second supplier is refused
        $this->actingAs($manager)->post("/purchase-requests/{$pr->id}/purchase-orders", [
            'supplier_id' => $supplierB->id, 'order_date' => now()->toDateString(),
            'items' => [['purchase_request_item_id' => $line->id, 'quantity' => 5, 'unit_price' => 90]],
        ])->assertSessionHasErrors('items.0.quantity');
        $this->assertSame(1, PurchaseOrder::count());

        // exactly the remaining 4 succeeds
        $this->actingAs($manager)->post("/purchase-requests/{$pr->id}/purchase-orders", [
            'supplier_id' => $supplierB->id, 'order_date' => now()->toDateString(),
            'items' => [['purchase_request_item_id' => $line->id, 'quantity' => 4, 'unit_price' => 90]],
        ])->assertSessionHasNoErrors();
        $this->assertSame(2, PurchaseOrder::count());
    }

    public function test_cancelling_a_purchase_order_frees_its_quantity_for_a_new_order(): void
    {
        $pr = $this->approvedRequest(quantity: 10);
        $line = $pr->items->first();
        $supplier = $this->supplier();
        $manager = $this->makeUser('branch_admin', null, ['purchase-order-manage']);

        $this->actingAs($manager)->post("/purchase-requests/{$pr->id}/purchase-orders", [
            'supplier_id' => $supplier->id, 'order_date' => now()->toDateString(),
            'items' => [['purchase_request_item_id' => $line->id, 'quantity' => 10, 'unit_price' => 100]],
        ]);
        $po = PurchaseOrder::firstOrFail();

        $this->actingAs($manager)->post("/purchase-orders/{$po->id}/cancel", ['reason' => 'Wrong supplier'])->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $po->fresh()->status);
        $this->assertSame(0, $line->fresh()->orderedQuantity());

        $this->actingAs($manager)->post("/purchase-requests/{$pr->id}/purchase-orders", [
            'supplier_id' => $supplier->id, 'order_date' => now()->toDateString(),
            'items' => [['purchase_request_item_id' => $line->id, 'quantity' => 10, 'unit_price' => 95]],
        ])->assertSessionHasNoErrors();
        $this->assertSame(2, PurchaseOrder::count());
    }

    public function test_sending_locks_it_but_a_draft_can_be_removed(): void
    {
        $pr = $this->approvedRequest(quantity: 20);
        $line = $pr->items->first();
        $supplier = $this->supplier();
        $manager = $this->makeUser('branch_admin', null, ['purchase-order-manage']);

        $this->actingAs($manager)->post("/purchase-requests/{$pr->id}/purchase-orders", [
            'supplier_id' => $supplier->id, 'order_date' => now()->toDateString(),
            'items' => [['purchase_request_item_id' => $line->id, 'quantity' => 10, 'unit_price' => 100]],
        ]);
        $po = PurchaseOrder::firstOrFail();

        $this->actingAs($manager)->post("/purchase-orders/{$po->id}/send")->assertSessionHasNoErrors();
        $this->assertSame('sent', $po->fresh()->status);

        // sent, so it can no longer be plain-deleted -- only cancelled
        $this->actingAs($manager)->delete("/purchase-orders/{$po->id}")->assertStatus(422);
        $this->assertNotNull(PurchaseOrder::find($po->id));

        // a fresh draft, however, can be removed outright
        $this->actingAs($manager)->post("/purchase-requests/{$pr->id}/purchase-orders", [
            'supplier_id' => $supplier->id, 'order_date' => now()->toDateString(),
            'items' => [['purchase_request_item_id' => $line->id, 'quantity' => 5, 'unit_price' => 100]],
        ]);
        $draft = PurchaseOrder::where('status', 'draft')->firstOrFail();
        $this->actingAs($manager)->delete("/purchase-orders/{$draft->id}")->assertSessionHasNoErrors();
        $this->assertNull(PurchaseOrder::find($draft->id));
    }

    public function test_only_purchase_order_manage_can_create_send_or_cancel(): void
    {
        $pr = $this->approvedRequest();
        $line = $pr->items->first();
        $supplier = $this->supplier();
        $bystander = $this->makeUser('teacher', null, []);

        $this->actingAs($bystander)->post("/purchase-requests/{$pr->id}/purchase-orders", [
            'supplier_id' => $supplier->id, 'order_date' => now()->toDateString(),
            'items' => [['purchase_request_item_id' => $line->id, 'quantity' => 1, 'unit_price' => 10]],
        ])->assertForbidden();
    }

    public function test_a_branch_admin_cannot_create_a_purchase_order_for_another_branchs_request(): void
    {
        $requesterB = $this->makeUser('teacher', $this->branchB->id, ['purchase-request-create']);
        $this->actingAs($requesterB)->post('/purchase-requests', [
            'purpose' => 'Books', 'priority' => 'normal', 'action' => 'submit',
            'items' => [['description' => 'Books', 'quantity' => 1, 'estimated_unit_price' => 10]],
        ]);
        $pr = PurchaseRequest::firstOrFail();
        $this->actingAs($this->owner)->post("/purchase-requests/{$pr->id}/approve");
        $line = $pr->fresh()->items->first();
        $supplier = $this->supplier();

        $adminA = $this->makeUser('branch_admin', $this->branchA->id, ['purchase-order-manage']);
        $this->actingAs($adminA)->post("/purchase-requests/{$pr->id}/purchase-orders", [
            'supplier_id' => $supplier->id, 'order_date' => now()->toDateString(),
            'items' => [['purchase_request_item_id' => $line->id, 'quantity' => 1, 'unit_price' => 10]],
        ])->assertForbidden();
    }

    public function test_a_purchase_order_can_be_traced_back_to_the_quotation_it_was_priced_from(): void
    {
        $pr = $this->approvedRequest();
        $line = $pr->items->first();
        $supplier = $this->supplier();
        $manager = $this->makeUser('branch_admin', null, ['quotation-manage', 'purchase-order-manage']);

        $this->actingAs($manager)->post("/purchase-requests/{$pr->id}/quotations", [
            'supplier_id' => $supplier->id, 'quotation_date' => now()->toDateString(),
            'items' => [['purchase_request_item_id' => $line->id, 'quantity' => 10, 'unit_price' => 90]],
        ]);
        $quotation = Quotation::firstOrFail();
        $this->actingAs($manager)->post("/quotations/{$quotation->id}/select");

        $this->actingAs($manager)->post("/purchase-requests/{$pr->id}/purchase-orders", [
            'quotation_id' => $quotation->id, 'supplier_id' => $supplier->id, 'order_date' => now()->toDateString(),
            'items' => [['purchase_request_item_id' => $line->id, 'quantity' => 10, 'unit_price' => 90]],
        ])->assertSessionHasNoErrors();

        $po = PurchaseOrder::firstOrFail();
        $this->assertSame($quotation->id, $po->quotation_id);
    }

    public function test_creating_sending_and_cancelling_are_all_in_the_audit_trail(): void
    {
        $pr = $this->approvedRequest();
        $line = $pr->items->first();
        $supplier = $this->supplier();
        $manager = $this->makeUser('branch_admin', null, ['purchase-order-manage']);

        $this->actingAs($manager)->post("/purchase-requests/{$pr->id}/purchase-orders", [
            'supplier_id' => $supplier->id, 'order_date' => now()->toDateString(),
            'items' => [['purchase_request_item_id' => $line->id, 'quantity' => 10, 'unit_price' => 100]],
        ]);
        $this->assertNotNull(\App\Models\AuditLog::where('action', 'purchase_order.created')->first());

        $po = PurchaseOrder::firstOrFail();
        $this->actingAs($manager)->post("/purchase-orders/{$po->id}/send");
        $this->actingAs($manager)->post("/purchase-orders/{$po->id}/cancel", ['reason' => 'No longer needed']);

        $statusChanges = \App\Models\AuditLog::where('action', 'purchase_order.status_changed')->count();
        $this->assertSame(2, $statusChanges, 'sent, then cancelled');
    }
}
