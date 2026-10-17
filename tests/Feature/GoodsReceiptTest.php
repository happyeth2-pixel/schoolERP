<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\GoodsReceipt;
use App\Models\InventoryItem;
use App\Models\InventoryStore;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Notifications\PurchaseRequestFulfilled;
use App\Services\Stock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

class GoodsReceiptTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchool();
    }

    private function catalogItem(): InventoryItem
    {
        return InventoryItem::create(['school_id' => $this->school->id, 'name' => 'Exercise Book', 'unit' => 'pcs', 'min_stock_level' => 0, 'reorder_level' => 0]);
    }

    private function store(?int $branchId = null): InventoryStore
    {
        return InventoryStore::create(['school_id' => $this->school->id, 'branch_id' => $branchId, 'name' => 'Main Store']);
    }

    private function supplier(): Supplier
    {
        return Supplier::create(['school_id' => $this->school->id, 'code' => 'SUP-'.uniqid(), 'name' => 'ABC Stationery']);
    }

    private function manager(?int $branchId = null): \App\Models\User
    {
        return $this->makeUser('branch_admin', $branchId, ['purchase-order-manage', 'goods-receipt-manage']);
    }

    /** Approved request -> draft order -> sent order, one line, quantity 10. Returns [$order, $orderItem]. */
    private function sentOrder(int $quantity = 10, ?InventoryItem $catalogItem = null, ?\App\Models\User $manager = null): array
    {
        $requester = $this->makeUser('teacher', $manager?->branch_id, ['purchase-request-create']);
        $this->actingAs($requester)->post('/purchase-requests', [
            'purpose' => 'Books', 'priority' => 'normal', 'action' => 'submit',
            'items' => [['item_id' => $catalogItem?->id, 'description' => 'Library books', 'quantity' => $quantity, 'estimated_unit_price' => 100]],
        ]);
        $pr = PurchaseRequest::firstOrFail();
        $this->actingAs($this->owner)->post("/purchase-requests/{$pr->id}/approve");
        $pr = $pr->fresh();
        $line = $pr->items->first();

        $manager ??= $this->manager();
        $supplier = $this->supplier();
        $this->actingAs($manager)->post("/purchase-requests/{$pr->id}/purchase-orders", [
            'supplier_id' => $supplier->id, 'order_date' => now()->toDateString(),
            'items' => [['purchase_request_item_id' => $line->id, 'quantity' => $quantity, 'unit_price' => 100]],
        ]);
        $order = PurchaseOrder::firstOrFail();
        $this->actingAs($manager)->post("/purchase-orders/{$order->id}/send");

        return [$order->fresh(), $order->items->first()];
    }

    public function test_a_goods_receipt_can_only_be_recorded_against_a_sent_or_partially_received_order(): void
    {
        $requester = $this->makeUser('teacher', null, ['purchase-request-create']);
        $this->actingAs($requester)->post('/purchase-requests', [
            'purpose' => 'Books', 'priority' => 'normal', 'action' => 'submit',
            'items' => [['description' => 'Books', 'quantity' => 5, 'estimated_unit_price' => 10]],
        ]);
        $pr = PurchaseRequest::firstOrFail();
        $this->actingAs($this->owner)->post("/purchase-requests/{$pr->id}/approve");
        $line = $pr->fresh()->items->first();
        $manager = $this->manager();
        $supplier = $this->supplier();

        $this->actingAs($manager)->post("/purchase-requests/{$pr->id}/purchase-orders", [
            'supplier_id' => $supplier->id, 'order_date' => now()->toDateString(),
            'items' => [['purchase_request_item_id' => $line->id, 'quantity' => 5, 'unit_price' => 10]],
        ]);
        $order = PurchaseOrder::firstOrFail(); // still draft, never sent
        $store = $this->store();

        $this->actingAs($manager)->post("/purchase-orders/{$order->id}/goods-receipts", [
            'store_id' => $store->id, 'received_date' => now()->toDateString(),
            'items' => [['purchase_order_item_id' => $order->items->first()->id, 'quantity_received' => 5]],
        ])->assertStatus(422);
        $this->assertSame(0, GoodsReceipt::count());
    }

    public function test_recording_a_goods_receipt_increases_stock_for_a_catalog_item(): void
    {
        $item = $this->catalogItem();
        [$order, $orderItem] = $this->sentOrder(quantity: 10, catalogItem: $item);
        $manager = $this->manager();
        $store = $this->store();

        $this->actingAs($manager)->post("/purchase-orders/{$order->id}/goods-receipts", [
            'store_id' => $store->id, 'received_date' => now()->toDateString(),
            'items' => [['purchase_order_item_id' => $orderItem->id, 'quantity_received' => 10]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(10, Stock::balance($item, $store));
        $movement = StockMovement::where('item_id', $item->id)->where('store_id', $store->id)->first();
        $this->assertNotNull($movement);
        $this->assertSame('purchase_receipt', $movement->type);
        $this->assertNotNull($movement->related_goods_receipt_item_id, 'traceable back to the goods receipt line');
        $this->assertSame('received', $order->fresh()->status);
    }

    public function test_a_free_text_line_is_received_without_touching_stock(): void
    {
        [$order, $orderItem] = $this->sentOrder(quantity: 5, catalogItem: null);
        $manager = $this->manager();
        $store = $this->store();

        $this->actingAs($manager)->post("/purchase-orders/{$order->id}/goods-receipts", [
            'store_id' => $store->id, 'received_date' => now()->toDateString(),
            'items' => [['purchase_order_item_id' => $orderItem->id, 'quantity_received' => 5]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, StockMovement::count(), 'a service/free-text line never moves stock');
        $this->assertSame('received', $order->fresh()->status);
        $this->assertSame(5, $orderItem->fresh()->receivedQuantity());
    }

    public function test_a_line_cannot_be_received_past_its_ordered_quantity(): void
    {
        $item = $this->catalogItem();
        [$order, $orderItem] = $this->sentOrder(quantity: 10, catalogItem: $item);
        $manager = $this->manager();
        $store = $this->store();

        $this->actingAs($manager)->post("/purchase-orders/{$order->id}/goods-receipts", [
            'store_id' => $store->id, 'received_date' => now()->toDateString(),
            'items' => [['purchase_order_item_id' => $orderItem->id, 'quantity_received' => 11]],
        ])->assertSessionHasErrors('items.0.quantity_received');
        $this->assertSame(0, GoodsReceipt::count());
        $this->assertSame(0, Stock::balance($item, $store));
    }

    public function test_receiving_partially_then_fully_transitions_the_order_through_both_statuses(): void
    {
        $item = $this->catalogItem();
        [$order, $orderItem] = $this->sentOrder(quantity: 10, catalogItem: $item);
        $manager = $this->manager();
        $store = $this->store();

        $this->actingAs($manager)->post("/purchase-orders/{$order->id}/goods-receipts", [
            'store_id' => $store->id, 'received_date' => now()->toDateString(),
            'items' => [['purchase_order_item_id' => $orderItem->id, 'quantity_received' => 6]],
        ]);
        $this->assertSame('partially_received', $order->fresh()->status);
        $this->assertSame(6, Stock::balance($item, $store));

        // the remaining 4 cannot be over-received...
        $this->actingAs($manager)->post("/purchase-orders/{$order->id}/goods-receipts", [
            'store_id' => $store->id, 'received_date' => now()->toDateString(),
            'items' => [['purchase_order_item_id' => $orderItem->id, 'quantity_received' => 5]],
        ])->assertSessionHasErrors('items.0.quantity_received');

        // ...but exactly the remaining 4 completes it
        $this->actingAs($manager)->post("/purchase-orders/{$order->id}/goods-receipts", [
            'store_id' => $store->id, 'received_date' => now()->toDateString(),
            'items' => [['purchase_order_item_id' => $orderItem->id, 'quantity_received' => 4]],
        ])->assertSessionHasNoErrors();
        $this->assertSame('received', $order->fresh()->status);
        $this->assertSame(10, Stock::balance($item, $store));
        $this->assertSame(2, GoodsReceipt::count());
    }

    public function test_only_goods_receipt_manage_can_record_a_receipt(): void
    {
        [$order, $orderItem] = $this->sentOrder();
        $bystander = $this->makeUser('teacher', null, []);
        $store = $this->store();

        $this->actingAs($bystander)->post("/purchase-orders/{$order->id}/goods-receipts", [
            'store_id' => $store->id, 'received_date' => now()->toDateString(),
            'items' => [['purchase_order_item_id' => $orderItem->id, 'quantity_received' => 1]],
        ])->assertForbidden();
    }

    public function test_a_branch_admin_cannot_record_a_receipt_for_another_branchs_order(): void
    {
        $managerA = $this->manager($this->branchA->id);
        [$order, $orderItem] = $this->sentOrder(manager: $this->makeUser('branch_admin', $this->branchB->id, ['purchase-order-manage', 'goods-receipt-manage']));
        $store = $this->store($this->branchA->id);

        $this->actingAs($managerA)->post("/purchase-orders/{$order->id}/goods-receipts", [
            'store_id' => $store->id, 'received_date' => now()->toDateString(),
            'items' => [['purchase_order_item_id' => $orderItem->id, 'quantity_received' => 1]],
        ])->assertForbidden();
    }

    public function test_a_goods_receipt_can_never_be_changed_or_removed(): void
    {
        $item = $this->catalogItem();
        [$order, $orderItem] = $this->sentOrder(quantity: 5, catalogItem: $item);
        $manager = $this->manager();
        $store = $this->store();

        $this->actingAs($manager)->post("/purchase-orders/{$order->id}/goods-receipts", [
            'store_id' => $store->id, 'received_date' => now()->toDateString(),
            'items' => [['purchase_order_item_id' => $orderItem->id, 'quantity_received' => 5]],
        ]);
        $receipt = GoodsReceipt::firstOrFail();

        $this->expectException(\App\Exceptions\DeletionBlockedException::class);
        $receipt->delete();
    }

    public function test_the_requester_is_notified_once_the_whole_request_is_fully_received(): void
    {
        $item = $this->catalogItem();
        $requester = $this->makeUser('teacher', null, ['purchase-request-create']);
        $this->actingAs($requester)->post('/purchase-requests', [
            'purpose' => 'Books', 'priority' => 'normal', 'action' => 'submit',
            'items' => [['item_id' => $item->id, 'description' => 'Library books', 'quantity' => 10, 'estimated_unit_price' => 100]],
        ]);
        $pr = PurchaseRequest::firstOrFail();
        $this->actingAs($this->owner)->post("/purchase-requests/{$pr->id}/approve");
        $line = $pr->fresh()->items->first();
        $manager = $this->manager();
        $supplier = $this->supplier();

        $this->actingAs($manager)->post("/purchase-requests/{$pr->id}/purchase-orders", [
            'supplier_id' => $supplier->id, 'order_date' => now()->toDateString(),
            'items' => [['purchase_request_item_id' => $line->id, 'quantity' => 10, 'unit_price' => 100]],
        ]);
        $order = PurchaseOrder::firstOrFail();
        $this->actingAs($manager)->post("/purchase-orders/{$order->id}/send");
        $store = $this->store();

        $this->actingAs($manager)->post("/purchase-orders/{$order->id}/goods-receipts", [
            'store_id' => $store->id, 'received_date' => now()->toDateString(),
            'items' => [['purchase_order_item_id' => $order->items->first()->id, 'quantity_received' => 6]],
        ]);
        $this->assertSame(0, $requester->notifications()->where('type', PurchaseRequestFulfilled::class)->count(), 'not yet -- only partially received');

        $this->actingAs($manager)->post("/purchase-orders/{$order->id}/goods-receipts", [
            'store_id' => $store->id, 'received_date' => now()->toDateString(),
            'items' => [['purchase_order_item_id' => $order->items->first()->id, 'quantity_received' => 4]],
        ]);
        $this->assertSame(1, $requester->notifications()->where('type', PurchaseRequestFulfilled::class)->count());
    }

    public function test_recording_a_goods_receipt_is_in_the_audit_trail(): void
    {
        $item = $this->catalogItem();
        [$order, $orderItem] = $this->sentOrder(quantity: 5, catalogItem: $item);
        $manager = $this->manager();
        $store = $this->store();

        $this->actingAs($manager)->post("/purchase-orders/{$order->id}/goods-receipts", [
            'store_id' => $store->id, 'received_date' => now()->toDateString(),
            'items' => [['purchase_order_item_id' => $orderItem->id, 'quantity_received' => 5]],
        ]);

        $this->assertNotNull(AuditLog::where('action', 'goods_receipt.created')->first());
        $this->assertNotNull(AuditLog::where('action', 'purchase_order.status_changed')->first());
    }
}
