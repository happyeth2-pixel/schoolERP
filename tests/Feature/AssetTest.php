<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AssetEvent;
use App\Models\InventoryStore;
use App\Notifications\AssetAssigned;
use App\Notifications\AssetRepairCompleted;
use App\Services\Assets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

class AssetTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchool();
    }

    private function store(?int $branchId = null): InventoryStore
    {
        return InventoryStore::create(['school_id' => $this->school->id, 'branch_id' => $branchId, 'name' => 'ICT Store']);
    }

    private function asset(?int $branchId = null, string $status = 'available', ?InventoryStore $store = null): Asset
    {
        $store ??= $this->store($branchId);

        return Asset::create([
            'school_id' => $this->school->id, 'branch_id' => $branchId, 'asset_tag' => 'AST-'.uniqid(),
            'name' => 'Laptop', 'current_store_id' => $store->id, 'condition' => 'good', 'status' => $status,
        ]);
    }

    public function test_assigning_an_available_asset_sets_its_holder_clears_its_store_and_notifies_them(): void
    {
        $asset = $this->asset();
        $teacher = $this->makeUser('teacher', null, []);

        Assets::assign($asset, $teacher, $this->owner, 'For classroom use');

        $asset->refresh();
        $this->assertSame('assigned', $asset->status);
        $this->assertSame($teacher->id, $asset->assigned_to);
        $this->assertNull($asset->current_store_id);
        $this->assertCount(1, $teacher->notifications);
        $this->assertSame(AssetAssigned::class, $teacher->notifications->first()->type);

        $event = AssetEvent::firstOrFail();
        $this->assertSame('assigned', $event->event_type);
        $this->assertSame($teacher->id, $event->to_user_id);
        $this->assertSame($this->owner->id, $event->performed_by);
    }

    public function test_an_already_assigned_asset_cannot_be_assigned_again_reassign_instead(): void
    {
        $asset = $this->asset();
        $a = $this->makeUser('teacher', null, []);
        $b = $this->makeUser('teacher', null, []);
        Assets::assign($asset, $a, $this->owner);

        $this->expectException(ValidationException::class);
        Assets::assign($asset, $b, $this->owner);
    }

    public function test_reassigning_moves_it_to_a_new_person_and_notifies_them_too(): void
    {
        $asset = $this->asset();
        $a = $this->makeUser('teacher', null, []);
        $b = $this->makeUser('teacher', null, []);
        Assets::assign($asset, $a, $this->owner);

        Assets::reassign($asset, $b, $this->owner, 'A left the school');

        $asset->refresh();
        $this->assertSame($b->id, $asset->assigned_to);
        $this->assertSame('assigned', $asset->status);
        $this->assertCount(1, $b->notifications);
        $event = AssetEvent::where('event_type', 'reassigned')->firstOrFail();
        $this->assertSame($a->id, $event->from_user_id);
        $this->assertSame($b->id, $event->to_user_id);
    }

    public function test_returning_an_assigned_asset_clears_its_holder_and_puts_it_back_in_a_store(): void
    {
        $asset = $this->asset();
        $teacher = $this->makeUser('teacher', null, []);
        $lab = InventoryStore::create(['school_id' => $this->school->id, 'name' => 'Lab Store']);
        Assets::assign($asset, $teacher, $this->owner);

        Assets::returnToStore($asset, $lab, $this->owner);

        $asset->refresh();
        $this->assertSame('available', $asset->status);
        $this->assertNull($asset->assigned_to);
        $this->assertSame($lab->id, $asset->current_store_id);
    }

    public function test_transfer_only_works_on_an_available_unassigned_asset(): void
    {
        $mainStore = $this->store();
        $asset = $this->asset(store: $mainStore);
        $lab = InventoryStore::create(['school_id' => $this->school->id, 'name' => 'Lab Store']);

        Assets::transfer($asset, $lab, $this->owner);
        $this->assertSame($lab->id, $asset->fresh()->current_store_id);

        $teacher = $this->makeUser('teacher', null, []);
        Assets::assign($asset->fresh(), $teacher, $this->owner);
        $this->expectException(ValidationException::class);
        Assets::transfer($asset->fresh(), $mainStore, $this->owner);
    }

    public function test_repair_cycle_returns_a_fixed_asset_to_its_previous_holder_and_notifies_them(): void
    {
        $asset = $this->asset();
        $teacher = $this->makeUser('teacher', null, []);
        Assets::assign($asset, $teacher, $this->owner);

        Assets::startRepair($asset->fresh(), $this->owner, 'Screen cracked');
        $this->assertSame('under_repair', $asset->fresh()->status);

        Assets::completeRepair($asset->fresh(), 'fixed', $this->owner, 'Screen replaced');
        $asset->refresh();
        $this->assertSame('assigned', $asset->status, 'returns to assigned since it still has a holder');
        $this->assertSame($teacher->id, $asset->assigned_to);
        $this->assertCount(1, $teacher->notifications()->where('type', AssetRepairCompleted::class)->get());
    }

    public function test_repair_cycle_can_end_in_damaged_instead_of_fixed(): void
    {
        $asset = $this->asset();
        Assets::startRepair($asset, $this->owner, 'Fell off a desk');

        Assets::completeRepair($asset->fresh(), 'damaged', $this->owner);
        $this->assertSame('damaged', $asset->fresh()->status);
    }

    public function test_retire_and_dispose_are_terminal(): void
    {
        $asset = $this->asset();
        Assets::retire($asset, $this->owner, 'End of useful life');
        $this->assertSame('retired', $asset->fresh()->status);

        Assets::dispose($asset->fresh(), $this->owner);
        $this->assertSame('disposed', $asset->fresh()->status);

        $this->expectException(ValidationException::class);
        Assets::dispose($asset->fresh(), $this->owner);
    }

    public function test_an_asset_event_can_never_be_changed_or_deleted(): void
    {
        $asset = $this->asset();
        $teacher = $this->makeUser('teacher', null, []);
        Assets::assign($asset, $teacher, $this->owner);
        $event = AssetEvent::firstOrFail();

        $this->expectException(\App\Exceptions\DeletionBlockedException::class);
        $event->update(['notes' => 'tampered']);
    }

    // ------------------------------------------------------------------ HTTP layer: permissions and branch scoping

    public function test_only_asset_manage_can_create_and_act_on_assets(): void
    {
        $store = $this->store();
        $viewer = $this->makeUser('teacher', null, ['asset-view']);
        $manager = $this->makeUser('branch_admin', null, ['asset-manage', 'asset-view']);

        $this->actingAs($viewer)->post('/assets', ['name' => 'Projector', 'store_id' => $store->id, 'condition' => 'good'])->assertForbidden();
        $this->actingAs($manager)->post('/assets', ['name' => 'Projector', 'store_id' => $store->id, 'condition' => 'good'])->assertSessionHasNoErrors();
        $asset = Asset::firstOrFail();
        $this->assertStringStartsWith('AST-', $asset->asset_tag);

        $teacher = $this->makeUser('teacher', null, []);
        $this->actingAs($viewer)->post("/assets/{$asset->id}/assign", ['user_id' => $teacher->id])->assertForbidden();
        $this->actingAs($manager)->post("/assets/{$asset->id}/assign", ['user_id' => $teacher->id])->assertSessionHasNoErrors();
    }

    public function test_a_branch_admin_cannot_act_on_another_branchs_asset(): void
    {
        $asset = $this->asset($this->branchB->id);
        $adminA = $this->makeUser('branch_admin', $this->branchA->id, ['asset-manage']);
        $teacher = $this->makeUser('teacher', null, []);

        $this->actingAs($adminA)->post("/assets/{$asset->id}/assign", ['user_id' => $teacher->id])->assertForbidden();
    }

    public function test_the_asset_list_and_show_page_are_scoped_by_branch(): void
    {
        $assetA = $this->asset($this->branchA->id);
        $assetB = $this->asset($this->branchB->id);
        $adminA = $this->makeUser('branch_admin', $this->branchA->id, ['asset-view']);

        $names = fn () => collect($this->props($this->actingAs($adminA)->get('/assets'))['assets']['data'])->pluck('asset_tag')->all();
        $this->assertContains($assetA->asset_tag, $names());
        $this->assertNotContains($assetB->asset_tag, $names());

        $this->actingAs($adminA)->get("/assets/{$assetB->id}")->assertForbidden();
    }

    // ------------------------------------------------------------------ linking to a real Supplier record

    public function test_creating_an_asset_with_a_free_text_supplier_still_works_exactly_as_before(): void
    {
        $manager = $this->makeUser('branch_admin', null, ['asset-manage']);
        $store = $this->store();

        $this->actingAs($manager)->post('/assets', [
            'name' => 'Laptop', 'store_id' => $store->id, 'condition' => 'good', 'supplier' => 'Hand-typed Vendor',
        ])->assertSessionHasNoErrors();

        $asset = Asset::where('name', 'Laptop')->firstOrFail();
        $this->assertSame('Hand-typed Vendor', $asset->supplier);
        $this->assertNull($asset->supplier_id);
    }

    public function test_picking_a_real_supplier_links_it_and_snapshots_its_current_name(): void
    {
        $manager = $this->makeUser('branch_admin', null, ['asset-manage']);
        $store = $this->store();
        $supplier = \App\Models\Supplier::create(['school_id' => $this->school->id, 'code' => 'SUP-1', 'name' => 'Addis Computers PLC']);

        $this->actingAs($manager)->post('/assets', [
            'name' => 'Laptop', 'store_id' => $store->id, 'condition' => 'good', 'supplier_id' => $supplier->id,
        ])->assertSessionHasNoErrors();

        $asset = Asset::where('name', 'Laptop')->firstOrFail();
        $this->assertSame($supplier->id, $asset->supplier_id);
        $this->assertSame('Addis Computers PLC', $asset->supplier, 'the free-text column is snapshotted from the linked supplier');

        // Renaming the supplier afterwards must not retroactively change what this asset already recorded.
        $supplier->update(['name' => 'Renamed Co']);
        $asset->refresh();
        $this->assertSame('Addis Computers PLC', $asset->supplier);
        $this->assertSame('Renamed Co', $asset->supplierRecord->name);
    }

    public function test_a_supplier_from_another_school_cannot_be_linked(): void
    {
        $manager = $this->makeUser('branch_admin', null, ['asset-manage']);
        $store = $this->store();
        $otherSchool = \App\Models\School::create(['name' => 'Other School', 'code' => 'OTH']);
        $foreignSupplier = \App\Models\Supplier::create(['school_id' => $otherSchool->id, 'code' => 'SUP-1', 'name' => 'Foreign Supplier']);

        $this->actingAs($manager)->post('/assets', [
            'name' => 'Laptop', 'store_id' => $store->id, 'condition' => 'good', 'supplier_id' => $foreignSupplier->id,
        ])->assertSessionHasErrors('supplier_id');
    }

    public function test_an_archived_supplier_cannot_be_linked_to_a_new_asset(): void
    {
        $manager = $this->makeUser('branch_admin', null, ['asset-manage']);
        $store = $this->store();
        $supplier = \App\Models\Supplier::create(['school_id' => $this->school->id, 'code' => 'SUP-1', 'name' => 'Gone Ltd']);
        $supplier->delete();

        $this->actingAs($manager)->post('/assets', [
            'name' => 'Laptop', 'store_id' => $store->id, 'condition' => 'good', 'supplier_id' => $supplier->id,
        ])->assertSessionHasErrors('supplier_id');
    }

    public function test_archiving_a_linked_supplier_leaves_the_assets_frozen_text_intact(): void
    {
        // Supplier uses SoftDeletes, so "deleting" it in the app never actually removes the row -- the FK's
        // nullOnDelete only matters for a genuine hard delete, which this app never performs on a supplier
        // (archive, not erase -- the same discipline every other module here follows). The real-world case
        // to prove is this one: a supplier is archived, and the asset that once pointed at it must keep
        // showing a reliable name regardless.
        $supplier = \App\Models\Supplier::create(['school_id' => $this->school->id, 'code' => 'SUP-1', 'name' => 'Addis Computers PLC']);
        $asset = $this->asset();
        $asset->update(['supplier' => 'Addis Computers PLC', 'supplier_id' => $supplier->id]);

        $supplier->delete();
        $asset->refresh();

        $this->assertNotNull($asset->id, 'the asset itself must still exist');
        $this->assertSame('Addis Computers PLC', $asset->supplier, 'the frozen text survives the supplier being archived');
        $this->assertNull($asset->supplierRecord, 'the live link-through is hidden once archived (SoftDeletes default scope) -- the frozen text is what is relied on, not this relation');
    }
}
