<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\InventoryStore;
use App\Models\Stocktake;
use App\Services\Stock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

class StocktakeTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchool();
    }

    private function item(string $name = 'Chalk'): InventoryItem
    {
        return InventoryItem::create(['school_id' => $this->school->id, 'name' => $name, 'unit' => 'box', 'min_stock_level' => 0, 'reorder_level' => 0]);
    }

    private function store(?int $branchId = null): InventoryStore
    {
        return InventoryStore::create(['school_id' => $this->school->id, 'branch_id' => $branchId, 'name' => 'Main Store']);
    }

    public function test_starting_a_stocktake_snapshots_the_current_balance_of_every_item(): void
    {
        $store = $this->store();
        $a = $this->item('Chalk');
        $b = $this->item('Markers');
        Stock::receive($a, $store, 100, $this->owner);
        Stock::receive($b, $store, 40, $this->owner);

        $this->actingAs($this->owner)->post('/stocktakes', ['store_id' => $store->id])->assertSessionHasNoErrors();

        $stocktake = Stocktake::firstOrFail();
        $this->assertSame('in_progress', $stocktake->status);
        $this->assertCount(2, $stocktake->lines);
        $this->assertSame(100, $stocktake->lines->firstWhere('item_id', $a->id)->system_quantity);
        $this->assertSame(40, $stocktake->lines->firstWhere('item_id', $b->id)->system_quantity);
        $this->assertNull($stocktake->lines->first()->physical_quantity, 'nothing is counted yet');
    }

    public function test_only_one_stocktake_can_be_in_progress_per_store_at_a_time(): void
    {
        $store = $this->store();
        $this->item();
        $this->actingAs($this->owner)->post('/stocktakes', ['store_id' => $store->id]);

        $this->actingAs($this->owner)->post('/stocktakes', ['store_id' => $store->id])->assertSessionHasErrors('store_id');
        $this->assertSame(1, Stocktake::count());
    }

    public function test_completing_requires_every_line_counted_and_a_reason_for_every_difference(): void
    {
        $store = $this->store();
        $item = $this->item();
        Stock::receive($item, $store, 50, $this->owner);
        $this->actingAs($this->owner)->post('/stocktakes', ['store_id' => $store->id]);
        $stocktake = Stocktake::firstOrFail();
        $line = $stocktake->lines->first();

        $this->actingAs($this->owner)->post("/stocktakes/{$stocktake->id}/complete")->assertSessionHasErrors('status');

        $this->actingAs($this->owner)->post("/stocktakes/{$stocktake->id}/counts", [
            'counts' => [$line->id => ['physical_quantity' => 47]],
        ])->assertSessionHasNoErrors();
        // a difference with no reason is refused
        $this->actingAs($this->owner)->post("/stocktakes/{$stocktake->id}/complete")->assertSessionHasErrors('status');

        $this->actingAs($this->owner)->post("/stocktakes/{$stocktake->id}/counts", [
            'counts' => [$line->id => ['physical_quantity' => 47, 'reason' => 'Some boxes damaged and thrown away']],
        ]);
        $this->actingAs($this->owner)->post("/stocktakes/{$stocktake->id}/complete")->assertSessionHasNoErrors();

        $stocktake->refresh();
        $this->assertSame('completed', $stocktake->status);
        $this->assertSame($this->owner->id, $stocktake->completed_by);
        $this->assertSame(47, Stock::balance($item, $store));
    }

    public function test_a_matching_count_needs_no_reason_and_creates_no_adjustment(): void
    {
        $store = $this->store();
        $item = $this->item();
        Stock::receive($item, $store, 50, $this->owner);
        $this->actingAs($this->owner)->post('/stocktakes', ['store_id' => $store->id]);
        $stocktake = Stocktake::firstOrFail();
        $line = $stocktake->lines->first();

        $this->actingAs($this->owner)->post("/stocktakes/{$stocktake->id}/counts", ['counts' => [$line->id => ['physical_quantity' => 50]]]);
        $movementsBefore = \App\Models\StockMovement::count();
        $this->actingAs($this->owner)->post("/stocktakes/{$stocktake->id}/complete")->assertSessionHasNoErrors();

        $this->assertSame($movementsBefore, \App\Models\StockMovement::count(), 'no adjustment movement for a line with no difference');
        $this->assertSame(50, Stock::balance($item, $store));
    }

    public function test_the_adjustment_reconciles_against_the_live_balance_not_a_stale_snapshot(): void
    {
        $store = $this->store();
        $item = $this->item();
        Stock::receive($item, $store, 50, $this->owner);
        $this->actingAs($this->owner)->post('/stocktakes', ['store_id' => $store->id]);
        $stocktake = Stocktake::firstOrFail();
        $line = $stocktake->lines->first();
        $this->assertSame(50, $line->system_quantity);

        // stock moves WHILE the count is in progress
        Stock::receive($item, $store, 10, $this->owner);
        $this->assertSame(60, Stock::balance($item, $store));

        $this->actingAs($this->owner)->post("/stocktakes/{$stocktake->id}/counts", [
            'counts' => [$line->id => ['physical_quantity' => 58, 'reason' => 'Found 58 on the shelf']],
        ]);
        $this->actingAs($this->owner)->post("/stocktakes/{$stocktake->id}/complete")->assertSessionHasNoErrors();

        $this->assertSame(58, Stock::balance($item, $store), 'reconciles to exactly the physical count regardless of the stale snapshot');
    }

    public function test_every_adjustment_movement_is_traceable_back_to_its_stocktake_line(): void
    {
        $store = $this->store();
        $item = $this->item();
        Stock::receive($item, $store, 50, $this->owner);
        $this->actingAs($this->owner)->post('/stocktakes', ['store_id' => $store->id]);
        $stocktake = Stocktake::firstOrFail();
        $line = $stocktake->lines->first();
        $this->actingAs($this->owner)->post("/stocktakes/{$stocktake->id}/counts", ['counts' => [$line->id => ['physical_quantity' => 45, 'reason' => 'Missing']]]);
        $this->actingAs($this->owner)->post("/stocktakes/{$stocktake->id}/complete");

        $movement = \App\Models\StockMovement::where('related_stocktake_line_id', $line->id)->firstOrFail();
        $this->assertSame('adjustment_decrease', $movement->type);
        $this->assertSame(5, $movement->quantity);
    }

    public function test_only_stock_adjust_can_start_or_complete_a_stocktake(): void
    {
        $store = $this->store();
        $this->item();
        $viewer = $this->makeUser('teacher', null, ['inventory-view']);

        $this->actingAs($viewer)->post('/stocktakes', ['store_id' => $store->id])->assertForbidden();
    }

    public function test_a_branch_admin_cannot_act_on_another_branchs_stocktake(): void
    {
        $storeB = $this->store($this->branchB->id);
        $this->item();
        $adminA = $this->makeUser('branch_admin', $this->branchA->id, ['stock-adjust']);
        $ownerB = $this->makeUser('branch_admin', $this->branchB->id, ['stock-adjust']);

        $this->actingAs($ownerB)->post('/stocktakes', ['store_id' => $storeB->id]);
        $stocktake = Stocktake::firstOrFail();

        $this->actingAs($adminA)->get("/stocktakes/{$stocktake->id}")->assertForbidden();
        $this->actingAs($adminA)->post("/stocktakes/{$stocktake->id}/complete")->assertForbidden();
    }
}
