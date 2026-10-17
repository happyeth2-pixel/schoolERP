<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\AssetEvent;
use App\Models\InventoryStore;
use App\Models\User;
use App\Notifications\AssetAssigned;
use App\Notifications\AssetRepairCompleted;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only way an asset's status/assigned_to/current_store_id ever change. Every call writes an
 * AssetEvent (the permanent history) and updates the asset's own snapshot fields in the same
 * transaction -- exactly the ledger-plus-snapshot shape App\Services\Stock uses for stock_movements.
 */
class Assets
{
    public static function assign(Asset $asset, User $toUser, User $actor, ?string $notes = null): AssetEvent
    {
        if (! in_array($asset->status, ['available'], true)) {
            throw ValidationException::withMessages(['status' => "This asset is not available to assign (it is currently {$asset->status})."]);
        }

        $event = self::record($asset, 'assigned', $actor, toUserId: $toUser->id, notes: $notes);
        $asset->update(['status' => 'assigned', 'assigned_to' => $toUser->id, 'current_store_id' => null]);
        $toUser->notify(new AssetAssigned($asset));

        return $event;
    }

    public static function reassign(Asset $asset, User $toUser, User $actor, ?string $notes = null): AssetEvent
    {
        if ($asset->status !== 'assigned') {
            throw ValidationException::withMessages(['status' => 'Only an assigned asset can be reassigned.']);
        }

        $fromUserId = $asset->assigned_to;
        $event = self::record($asset, 'reassigned', $actor, fromUserId: $fromUserId, toUserId: $toUser->id, notes: $notes);
        $asset->update(['assigned_to' => $toUser->id]);
        $toUser->notify(new AssetAssigned($asset));

        return $event;
    }

    public static function returnToStore(Asset $asset, InventoryStore $store, User $actor, ?string $notes = null): AssetEvent
    {
        if ($asset->status !== 'assigned') {
            throw ValidationException::withMessages(['status' => 'Only an assigned asset can be returned.']);
        }

        $fromUserId = $asset->assigned_to;
        $event = self::record($asset, 'returned', $actor, fromUserId: $fromUserId, toStoreId: $store->id, notes: $notes);
        $asset->update(['status' => 'available', 'assigned_to' => null, 'current_store_id' => $store->id]);

        return $event;
    }

    public static function transfer(Asset $asset, InventoryStore $toStore, User $actor, ?string $notes = null): AssetEvent
    {
        if ($asset->status !== 'available') {
            throw ValidationException::withMessages(['status' => 'Only an available (unassigned) asset can be transferred between stores.']);
        }

        $fromStoreId = $asset->current_store_id;
        $event = self::record($asset, 'transferred', $actor, fromStoreId: $fromStoreId, toStoreId: $toStore->id, notes: $notes);
        $asset->update(['current_store_id' => $toStore->id]);

        return $event;
    }

    public static function startRepair(Asset $asset, User $actor, string $notes): AssetEvent
    {
        if (! in_array($asset->status, ['available', 'assigned'], true)) {
            throw ValidationException::withMessages(['status' => "This asset cannot start repair from its current status ({$asset->status})."]);
        }

        $event = self::record($asset, 'repair_started', $actor, notes: $notes);
        $asset->update(['status' => 'under_repair']);

        return $event;
    }

    /** $outcome: 'fixed' returns it to whatever it was before (assigned or available), 'damaged' marks it so. */
    public static function completeRepair(Asset $asset, string $outcome, User $actor, ?string $notes = null): AssetEvent
    {
        if ($asset->status !== 'under_repair') {
            throw ValidationException::withMessages(['status' => 'This asset is not under repair.']);
        }

        $event = self::record($asset, 'repair_completed', $actor, notes: $notes);
        $asset->update(['status' => $outcome === 'fixed' ? ($asset->assigned_to ? 'assigned' : 'available') : 'damaged']);

        if ($asset->assigned_to) {
            $asset->assignee->notify(new AssetRepairCompleted($asset, $outcome));
        }

        return $event;
    }

    public static function markLost(Asset $asset, User $actor, string $notes): AssetEvent
    {
        $event = self::record($asset, 'marked_lost', $actor, notes: $notes);
        $asset->update(['status' => 'lost']);

        return $event;
    }

    public static function markDamaged(Asset $asset, User $actor, string $notes): AssetEvent
    {
        $event = self::record($asset, 'marked_damaged', $actor, notes: $notes);
        $asset->update(['status' => 'damaged']);

        return $event;
    }

    public static function retire(Asset $asset, User $actor, ?string $notes = null): AssetEvent
    {
        if ($asset->status === 'disposed') {
            throw ValidationException::withMessages(['status' => 'A disposed asset cannot be retired.']);
        }

        $event = self::record($asset, 'retired', $actor, notes: $notes);
        $asset->update(['status' => 'retired', 'assigned_to' => null]);

        return $event;
    }

    public static function dispose(Asset $asset, User $actor, ?string $notes = null): AssetEvent
    {
        if ($asset->status === 'disposed') {
            throw ValidationException::withMessages(['status' => 'This asset is already disposed.']);
        }

        $event = self::record($asset, 'disposed', $actor, notes: $notes);
        $asset->update(['status' => 'disposed', 'assigned_to' => null]);

        return $event;
    }

    private static function record(Asset $asset, string $type, User $actor, ?int $fromUserId = null, ?int $toUserId = null, ?int $fromStoreId = null, ?int $toStoreId = null, ?string $notes = null): AssetEvent
    {
        return DB::transaction(fn () => AssetEvent::create([
            'asset_id' => $asset->id,
            'event_type' => $type,
            'from_user_id' => $fromUserId,
            'to_user_id' => $toUserId,
            'from_store_id' => $fromStoreId,
            'to_store_id' => $toStoreId,
            'notes' => $notes,
            'performed_by' => $actor->id,
        ]));
    }
}
