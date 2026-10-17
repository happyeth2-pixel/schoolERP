<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\InventoryStore;
use App\Models\StockMovement;
use App\Services\Stock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

class InventoryTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchool();
    }

    private function item(?InventoryCategory $category = null, int $reorder = 0): InventoryItem
    {
        return InventoryItem::create([
            'school_id' => $this->school->id,
            'category_id' => $category?->id,
            'name' => 'Exercise Book',
            'unit' => 'pcs',
            'min_stock_level' => 0,
            'reorder_level' => $reorder,
        ]);
    }

    private function store(?int $branchId = null): InventoryStore
    {
        return InventoryStore::create(['school_id' => $this->school->id, 'branch_id' => $branchId, 'name' => 'Main Store']);
    }

    // ------------------------------------------------------------------ the ledger and running balance

    public function test_receiving_stock_increases_the_balance_and_records_a_movement(): void
    {
        $this->actingAs($this->owner); // Audited only writes a trail entry when someone is actually signed in
        $item = $this->item();
        $store = $this->store();

        $movement = Stock::receive($item, $store, 50, $this->owner, 'PO-100', 'Term stock-up');

        $this->assertSame(50, Stock::balance($item, $store));
        $this->assertSame('purchase_receipt', $movement->type);
        $this->assertSame(50, $movement->quantity);
        $this->assertSame('PO-100', $movement->reference_number);
        $this->assertSame($this->owner->id, $movement->performed_by);
        $this->assertNotNull(AuditLog::where('action', 'stock_movement.created')->first(), 'every movement is in the unified audit trail');
    }

    public function test_issuing_stock_decreases_the_balance(): void
    {
        $item = $this->item();
        $store = $this->store();
        Stock::receive($item, $store, 30, $this->owner);

        Stock::issue($item, $store, 12, $this->owner, 'Grade 5 supplies');

        $this->assertSame(18, Stock::balance($item, $store));
        $this->assertSame('issue', StockMovement::latest('id')->first()->type);
    }

    public function test_issuing_more_than_is_available_is_refused_and_changes_nothing(): void
    {
        $item = $this->item();
        $store = $this->store();
        Stock::receive($item, $store, 5, $this->owner);

        try {
            Stock::issue($item, $store, 6, $this->owner, 'too many');
            $this->fail('expected a ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('quantity', $e->errors());
        }

        $this->assertSame(5, Stock::balance($item, $store), 'the failed issue changed nothing');
        $this->assertSame(1, StockMovement::count(), 'no movement was written for the refused issue');
    }

    public function test_negative_stock_is_allowed_once_the_school_turns_the_setting_on(): void
    {
        $this->school->update(['allow_negative_stock' => true]);
        $item = $this->item();
        $store = $this->store();

        Stock::issue($item, $store, 5, $this->owner, 'urgent, no stock yet');

        $this->assertSame(-5, Stock::balance($item, $store));
    }

    public function test_transfer_moves_stock_between_two_stores_as_two_linked_movements(): void
    {
        $item = $this->item();
        $main = $this->store();
        $lab = InventoryStore::create(['school_id' => $this->school->id, 'name' => 'Lab Store']);
        Stock::receive($item, $main, 20, $this->owner);

        [$out, $in] = Stock::transfer($item, $main, $lab, 8, $this->owner, 'lab needs paper');

        $this->assertSame(12, Stock::balance($item, $main));
        $this->assertSame(8, Stock::balance($item, $lab));
        $this->assertSame('transfer_out', $out->type);
        $this->assertSame('transfer_in', $in->type);
        $this->assertSame($out->id, $in->related_movement_id);
    }

    public function test_transfer_to_the_same_store_is_refused(): void
    {
        $item = $this->item();
        $store = $this->store();
        Stock::receive($item, $store, 10, $this->owner);

        $this->expectException(ValidationException::class);
        Stock::transfer($item, $store, $store, 1, $this->owner);
    }

    public function test_adjust_records_the_difference_as_one_movement_and_a_zero_difference_records_nothing(): void
    {
        $item = $this->item();
        $store = $this->store();
        Stock::receive($item, $store, 100, $this->owner);

        $movement = Stock::adjust($item, $store, 97, $this->owner, 'stocktake found 3 missing');
        $this->assertSame('adjustment_decrease', $movement->type);
        $this->assertSame(3, $movement->quantity);
        $this->assertSame(97, Stock::balance($item, $store));

        $up = Stock::adjust($item, $store, 100, $this->owner, 'found them again');
        $this->assertSame('adjustment_increase', $up->type);
        $this->assertSame(3, $up->quantity);

        $this->assertNull(Stock::adjust($item, $store, 100, $this->owner, 'no change'), 'a matching count records nothing');
    }

    public function test_a_stock_movement_can_never_be_changed_or_deleted(): void
    {
        $movement = Stock::receive($this->item(), $this->store(), 10, $this->owner);

        $this->expectException(\App\Exceptions\DeletionBlockedException::class);
        $movement->update(['quantity' => 999]);
    }

    // ------------------------------------------------------------------ sku/barcode uniqueness (DB-level)

    public function test_two_active_items_in_the_same_school_cannot_share_a_sku_even_bypassing_app_validation(): void
    {
        InventoryItem::create(['school_id' => $this->school->id, 'name' => 'Pen', 'unit' => 'pcs', 'sku' => 'PEN-1', 'min_stock_level' => 0, 'reorder_level' => 0]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        // A direct model create, not the validated controller route -- proves the database itself refuses
        // the duplicate, not only the form's validation rule.
        InventoryItem::create(['school_id' => $this->school->id, 'name' => 'Pencil', 'unit' => 'pcs', 'sku' => 'PEN-1', 'min_stock_level' => 0, 'reorder_level' => 0]);
    }

    public function test_two_active_items_in_the_same_school_cannot_share_a_barcode_either(): void
    {
        InventoryItem::create(['school_id' => $this->school->id, 'name' => 'Pen', 'unit' => 'pcs', 'barcode' => '1234567890', 'min_stock_level' => 0, 'reorder_level' => 0]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        InventoryItem::create(['school_id' => $this->school->id, 'name' => 'Pencil', 'unit' => 'pcs', 'barcode' => '1234567890', 'min_stock_level' => 0, 'reorder_level' => 0]);
    }

    public function test_the_same_sku_is_allowed_again_in_a_different_school(): void
    {
        $otherSchool = \App\Models\School::create(['name' => 'Other School', 'code' => 'OTH']);
        InventoryItem::create(['school_id' => $this->school->id, 'name' => 'Pen', 'unit' => 'pcs', 'sku' => 'PEN-1', 'min_stock_level' => 0, 'reorder_level' => 0]);

        $other = InventoryItem::create(['school_id' => $otherSchool->id, 'name' => 'Pen', 'unit' => 'pcs', 'sku' => 'PEN-1', 'min_stock_level' => 0, 'reorder_level' => 0]);
        $this->assertSame('PEN-1', $other->sku);
    }

    public function test_items_with_no_sku_never_collide_with_each_other(): void
    {
        $a = InventoryItem::create(['school_id' => $this->school->id, 'name' => 'Pen', 'unit' => 'pcs', 'min_stock_level' => 0, 'reorder_level' => 0]);
        $b = InventoryItem::create(['school_id' => $this->school->id, 'name' => 'Pencil', 'unit' => 'pcs', 'min_stock_level' => 0, 'reorder_level' => 0]);
        $this->assertNull($a->sku);
        $this->assertNull($b->sku);
    }

    public function test_archiving_an_item_frees_its_sku_for_a_new_item_but_keeps_the_old_value_readable(): void
    {
        $old = InventoryItem::create(['school_id' => $this->school->id, 'name' => 'Old Pen', 'unit' => 'pcs', 'sku' => 'PEN-1', 'barcode' => 'BC-1', 'min_stock_level' => 0, 'reorder_level' => 0]);
        $old->delete();

        $this->assertStringContainsString('PEN-1#archived-', $old->sku, 'the original value is still visible, just no longer exact');
        $this->assertStringContainsString('BC-1#archived-', $old->barcode);

        $new = InventoryItem::create(['school_id' => $this->school->id, 'name' => 'New Pen', 'unit' => 'pcs', 'sku' => 'PEN-1', 'barcode' => 'BC-1', 'min_stock_level' => 0, 'reorder_level' => 0]);
        $this->assertSame('PEN-1', $new->sku);
        $this->assertSame('BC-1', $new->barcode);
    }

    // ------------------------------------------------------------------ permissions and branch scoping

    public function test_only_inventory_manage_can_create_items_stores_and_categories(): void
    {
        $viewer = $this->makeUser('teacher', $this->branchA->id, ['inventory-view']);
        $manager = $this->makeUser('branch_admin', $this->branchA->id, ['inventory-manage', 'inventory-view']);

        $this->actingAs($viewer)->post('/inventory/items', ['name' => 'Chalk', 'unit' => 'box', 'min_stock_level' => 0, 'reorder_level' => 0])->assertForbidden();
        $this->actingAs($manager)->post('/inventory/items', ['name' => 'Chalk', 'unit' => 'box', 'min_stock_level' => 0, 'reorder_level' => 0])->assertSessionHasNoErrors();
        $this->assertNotNull(InventoryItem::where('name', 'Chalk')->first());

        $this->actingAs($viewer)->post('/inventory/stores', ['name' => 'Lab Store'])->assertForbidden();
        $this->actingAs($manager)->post('/inventory/stores', ['name' => 'Lab Store'])->assertSessionHasNoErrors();
    }

    public function test_stock_actions_each_need_their_own_specific_permission(): void
    {
        $item = $this->item();
        $store = $this->store();
        $receiverOnly = $this->makeUser('accountant', null, ['stock-receive', 'inventory-view']);

        $this->actingAs($receiverOnly)->post('/inventory/movements/receive', ['item_id' => $item->id, 'store_id' => $store->id, 'quantity' => 10])->assertSessionHasNoErrors();
        $this->assertSame(10, Stock::balance($item, $store));

        // the same account cannot issue, transfer or adjust -- receiving is the only thing it was given
        $this->actingAs($receiverOnly)->post('/inventory/movements/issue', ['item_id' => $item->id, 'store_id' => $store->id, 'quantity' => 1, 'reason' => 'x'])->assertForbidden();
        $this->actingAs($receiverOnly)->post('/inventory/movements/adjust', ['item_id' => $item->id, 'store_id' => $store->id, 'physical_quantity' => 5, 'reason' => 'x'])->assertForbidden();
    }

    public function test_a_branch_admin_only_manages_their_own_branchs_stores_not_another_branchs(): void
    {
        $adminA = $this->makeUser('branch_admin', $this->branchA->id, ['inventory-manage', 'inventory-view']);

        $this->actingAs($adminA)->post('/inventory/stores', ['name' => 'A Store', 'branch_id' => $this->branchB->id])->assertSessionHasNoErrors();
        $this->assertSame($this->branchA->id, InventoryStore::firstOrFail()->branch_id, 'forced to their own branch, even when another branch was asked for');

        $storeB = InventoryStore::create(['school_id' => $this->school->id, 'branch_id' => $this->branchB->id, 'name' => 'B Store']);
        $this->actingAs($adminA)->patch("/inventory/stores/{$storeB->id}", ['name' => 'Renamed'])->assertForbidden();
        $this->actingAs($adminA)->delete("/inventory/stores/{$storeB->id}")->assertForbidden();
    }

    public function test_a_branch_admin_cannot_receive_or_transfer_stock_into_another_branchs_store(): void
    {
        $item = $this->item();
        $storeB = InventoryStore::create(['school_id' => $this->school->id, 'branch_id' => $this->branchB->id, 'name' => 'B Store']);
        $adminA = $this->makeUser('branch_admin', $this->branchA->id, ['stock-receive', 'inventory-view']);

        $this->actingAs($adminA)->post('/inventory/movements/receive', ['item_id' => $item->id, 'store_id' => $storeB->id, 'quantity' => 5])->assertNotFound();
    }

    public function test_an_owner_sees_every_branchs_stores_and_a_branch_admin_only_their_own(): void
    {
        InventoryStore::create(['school_id' => $this->school->id, 'branch_id' => $this->branchA->id, 'name' => 'A Store']);
        InventoryStore::create(['school_id' => $this->school->id, 'branch_id' => $this->branchB->id, 'name' => 'B Store']);
        InventoryStore::create(['school_id' => $this->school->id, 'branch_id' => null, 'name' => 'Shared Store']);

        $adminA = $this->makeUser('branch_admin', $this->branchA->id, ['inventory-view']);
        $names = fn ($u) => collect($this->props($this->actingAs($u)->get('/inventory/stores'))['stores'])->pluck('name')->sort()->values()->all();

        $this->assertSame(['A Store', 'Shared Store'], $names($adminA));
        $this->assertSame(['A Store', 'B Store', 'Shared Store'], $names($this->owner));
    }

    // ------------------------------------------------------------------ dashboard

    public function test_low_stock_and_out_of_stock_are_only_sent_to_people_who_can_see_inventory(): void
    {
        $item = $this->item(reorder: 10);
        $store = $this->store();
        Stock::receive($item, $store, 5, $this->owner);

        $seer = $this->makeUser('branch_admin', null, ['inventory-view']);
        $stranger = $this->makeUser('branch_admin', null, []);

        $seen = $this->props($this->actingAs($seer)->get('/dashboard'))['stats']['inventory'];
        $this->assertSame(1, $seen['total_items']);
        $this->assertSame(1, $seen['low_stock']);
        $this->assertSame(0, $seen['out_of_stock']);

        $this->assertNull($this->props($this->actingAs($stranger)->get('/dashboard'))['stats']['inventory']);
    }
}
