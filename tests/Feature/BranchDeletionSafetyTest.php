<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AssetEvent;
use App\Models\Branch;
use App\Models\CalendarEvent;
use App\Models\InventoryItem;
use App\Models\InventoryStore;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Models\Stocktake;
use App\Models\StocktakeLine;
use App\Services\Stock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

/**
 * There is no `branches.destroy` route in the app today, so this can only happen via direct model/DB
 * access -- but nothing stops a future feature, a data migration, or an admin in tinker from doing exactly
 * that, and the foreign key behaviour is a property of the schema itself, not of whichever route happens to
 * call delete() this month. Before the branch_id fix (see the 2026_10_21 migration), deleting a Branch
 * cascaded through inventory_stores/assets/stocktakes/purchase_requests and from there into
 * stock_movements/stock_balances/asset_events/stocktake_lines -- silently destroying records this project
 * documents elsewhere as permanent, append-only history. These tests prove that chain is actually broken,
 * not just reasoned about.
 */
class BranchDeletionSafetyTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchool();
    }

    public function test_deleting_a_branch_unscopes_its_inventory_store_instead_of_deleting_its_stock_history(): void
    {
        $store = InventoryStore::create(['school_id' => $this->school->id, 'branch_id' => $this->branchA->id, 'name' => 'A Store']);
        $item = InventoryItem::create(['school_id' => $this->school->id, 'name' => 'Pen', 'unit' => 'pcs', 'min_stock_level' => 0, 'reorder_level' => 0]);
        $movement = Stock::receive($item, $store, 10, $this->owner);

        $this->branchA->delete();

        $this->assertNotNull(InventoryStore::find($store->id), 'the store itself must survive');
        $this->assertNull(InventoryStore::find($store->id)->branch_id, 'it is unscoped to "every branch", not orphaned or broken');
        $this->assertNotNull(\App\Models\StockMovement::find($movement->id), 'the permanent stock ledger entry must survive a branch deletion untouched');
        $this->assertDatabaseHas('stock_balances', ['item_id' => $item->id, 'store_id' => $store->id, 'quantity' => 10]);
    }

    public function test_deleting_a_branch_unscopes_an_asset_instead_of_deleting_its_event_history(): void
    {
        $store = InventoryStore::create(['school_id' => $this->school->id, 'branch_id' => $this->branchA->id, 'name' => 'A Store']);
        $asset = Asset::create(['school_id' => $this->school->id, 'branch_id' => $this->branchA->id, 'asset_tag' => 'AST-1', 'name' => 'Laptop', 'current_store_id' => $store->id, 'condition' => 'good', 'status' => 'available']);
        \App\Services\Assets::assign($asset, $this->owner, $this->owner, 'test');
        $this->assertSame(1, AssetEvent::count());

        $this->branchA->delete();

        $this->assertNotNull(Asset::find($asset->id), 'the asset itself must survive');
        $this->assertNull(Asset::find($asset->id)->branch_id);
        $this->assertSame(1, AssetEvent::count(), 'its permanent event history must survive untouched');
    }

    public function test_deleting_a_branch_unscopes_a_stocktake_instead_of_deleting_its_lines(): void
    {
        $store = InventoryStore::create(['school_id' => $this->school->id, 'branch_id' => $this->branchA->id, 'name' => 'A Store']);
        $item = InventoryItem::create(['school_id' => $this->school->id, 'name' => 'Pen', 'unit' => 'pcs', 'min_stock_level' => 0, 'reorder_level' => 0]);
        $stocktake = Stocktake::create(['school_id' => $this->school->id, 'branch_id' => $this->branchA->id, 'store_id' => $store->id, 'status' => 'in_progress', 'started_by' => $this->owner->id, 'started_at' => now()]);
        StocktakeLine::create(['stocktake_id' => $stocktake->id, 'item_id' => $item->id, 'system_quantity' => 0]);

        $this->branchA->delete();

        $this->assertNotNull(Stocktake::find($stocktake->id));
        $this->assertNull(Stocktake::find($stocktake->id)->branch_id);
        $this->assertSame(1, StocktakeLine::where('stocktake_id', $stocktake->id)->count(), 'its line detail must survive untouched');
    }

    public function test_deleting_a_branch_unscopes_a_purchase_request_instead_of_failing_or_deleting_it(): void
    {
        $request = PurchaseRequest::create(['school_id' => $this->school->id, 'branch_id' => $this->branchA->id, 'requester_id' => $this->owner->id, 'number' => 'PR-1', 'purpose' => 'Supplies', 'status' => 'draft']);
        PurchaseRequestItem::create(['purchase_request_id' => $request->id, 'description' => 'Paper', 'quantity' => 1]);

        $this->branchA->delete();

        $this->assertNotNull(PurchaseRequest::find($request->id));
        $this->assertNull(PurchaseRequest::find($request->id)->branch_id);
    }

    public function test_deleting_a_branch_unscopes_a_calendar_event_instead_of_deleting_it(): void
    {
        $event = CalendarEvent::create(['school_id' => $this->school->id, 'branch_id' => $this->branchA->id, 'title' => 'Sports Day', 'audience' => 'everyone', 'start_date' => now()]);

        $this->branchA->delete();

        $this->assertNotNull(CalendarEvent::find($event->id));
        $this->assertNull(CalendarEvent::find($event->id)->branch_id);
    }
}
