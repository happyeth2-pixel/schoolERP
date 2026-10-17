<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\InventoryStore;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\User;
use App\Notifications\LowStockAlert;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * The only way stock quantities are ever changed. Every call writes a StockMovement (the permanent ledger)
 * and updates that (item, store)'s StockBalance in the SAME transaction, under a row lock -- exactly the
 * technique NumberSequence uses for invoice numbers, applied here to keep two concurrent issues of the last
 * few units from both succeeding. Quantities are never edited directly; stock_balances.quantity is only ever
 * written from here.
 */
class Stock
{
    public static function opening(InventoryItem $item, InventoryStore $store, int $quantity, User $user, ?string $reason = null): StockMovement
    {
        return self::move($item, $store, 'opening', $quantity, $user, null, $reason);
    }

    public static function receive(InventoryItem $item, InventoryStore $store, int $quantity, User $user, ?string $referenceNumber = null, ?string $reason = null, ?int $relatedGoodsReceiptItemId = null): StockMovement
    {
        return self::move($item, $store, 'purchase_receipt', $quantity, $user, $referenceNumber, $reason, null, null, $relatedGoodsReceiptItemId);
    }

    public static function returnStock(InventoryItem $item, InventoryStore $store, int $quantity, User $user, ?string $reason = null): StockMovement
    {
        return self::move($item, $store, 'return', $quantity, $user, null, $reason);
    }

    public static function issue(InventoryItem $item, InventoryStore $store, int $quantity, User $user, ?string $reason = null, ?int $relatedRequisitionItemId = null): StockMovement
    {
        return self::move($item, $store, 'issue', $quantity, $user, null, $reason, null, $relatedRequisitionItemId);
    }

    public static function markDamaged(InventoryItem $item, InventoryStore $store, int $quantity, User $user, string $reason): StockMovement
    {
        return self::move($item, $store, 'damaged', $quantity, $user, null, $reason);
    }

    public static function markLost(InventoryItem $item, InventoryStore $store, int $quantity, User $user, string $reason): StockMovement
    {
        return self::move($item, $store, 'lost', $quantity, $user, null, $reason);
    }

    /** Moves stock from one store to another as two linked, one-directional movements (see class docblock). */
    public static function transfer(InventoryItem $item, InventoryStore $from, InventoryStore $to, int $quantity, User $user, ?string $reason = null): array
    {
        if ($from->id === $to->id) {
            throw ValidationException::withMessages(['to_store_id' => 'Choose a different store to transfer into.']);
        }

        return DB::transaction(function () use ($item, $from, $to, $quantity, $user, $reason) {
            $out = self::move($item, $from, 'transfer_out', $quantity, $user, null, $reason);
            $in = self::move($item, $to, 'transfer_in', $quantity, $user, null, $reason, $out->id);

            return [$out, $in];
        });
    }

    /**
     * Sets a store's balance to a known count (a stocktake, or a manual correction), recording the
     * difference as one adjustment movement. Returns null when the count already matches -- nothing to record.
     */
    public static function adjust(InventoryItem $item, InventoryStore $store, int $physicalQuantity, User $user, string $reason, ?int $relatedStocktakeLineId = null): ?StockMovement
    {
        return DB::transaction(function () use ($item, $store, $physicalQuantity, $user, $reason, $relatedStocktakeLineId) {
            $balance = self::lockedBalance($item->id, $store->id);
            $diff = $physicalQuantity - $balance->quantity;

            if ($diff === 0) {
                return null;
            }

            return self::applyLocked($balance, $item, $store, $diff > 0 ? 'adjustment_increase' : 'adjustment_decrease', abs($diff), $user, null, $reason, null, null, $relatedStocktakeLineId);
        });
    }

    public static function balance(InventoryItem $item, InventoryStore $store): int
    {
        return (int) (StockBalance::where('item_id', $item->id)->where('store_id', $store->id)->value('quantity') ?? 0);
    }

    private static function move(InventoryItem $item, InventoryStore $store, string $type, int $quantity, User $user, ?string $referenceNumber, ?string $reason, ?int $relatedMovementId = null, ?int $relatedRequisitionItemId = null, ?int $relatedGoodsReceiptItemId = null): StockMovement
    {
        if ($quantity <= 0) {
            throw ValidationException::withMessages(['quantity' => 'Enter a quantity greater than zero.']);
        }

        return DB::transaction(function () use ($item, $store, $type, $quantity, $user, $referenceNumber, $reason, $relatedMovementId, $relatedRequisitionItemId, $relatedGoodsReceiptItemId) {
            $balance = self::lockedBalance($item->id, $store->id);

            return self::applyLocked($balance, $item, $store, $type, $quantity, $user, $referenceNumber, $reason, $relatedMovementId, $relatedRequisitionItemId, null, $relatedGoodsReceiptItemId);
        });
    }

    /** $balance must already be locked (see lockedBalance) and the caller already inside a transaction. */
    private static function applyLocked(StockBalance $balance, InventoryItem $item, InventoryStore $store, string $type, int $quantity, User $user, ?string $referenceNumber, ?string $reason, ?int $relatedMovementId, ?int $relatedRequisitionItemId = null, ?int $relatedStocktakeLineId = null, ?int $relatedGoodsReceiptItemId = null): StockMovement
    {
        $increase = in_array($type, StockMovement::INCREASE_TYPES, true);
        $oldQuantity = $balance->quantity;
        $newQuantity = $oldQuantity + ($increase ? $quantity : -$quantity);

        if ($newQuantity < 0 && ! $item->school->allow_negative_stock) {
            throw ValidationException::withMessages([
                'quantity' => "Only {$balance->quantity} of {$item->name} available at {$store->name}.",
            ]);
        }

        $balance->quantity = $newQuantity;
        $balance->save();

        $movement = StockMovement::create([
            'school_id' => $item->school_id,
            'item_id' => $item->id,
            'store_id' => $store->id,
            'type' => $type,
            'quantity' => $quantity,
            'reference_number' => $referenceNumber,
            'reason' => $reason,
            'related_movement_id' => $relatedMovementId,
            'related_requisition_item_id' => $relatedRequisitionItemId,
            'related_stocktake_line_id' => $relatedStocktakeLineId,
            'related_goods_receipt_item_id' => $relatedGoodsReceiptItemId,
            'performed_by' => $user->id,
        ]);

        if (! $increase) {
            self::notifyIfLowStockCrossed($item, $store, $oldQuantity, $newQuantity);
        }

        return $movement;
    }

    /**
     * Tells inventory staff once, at the moment stock actually CROSSES into low/out of stock -- not on
     * every later issue while it stays there, which would just be noise (the brief's own "don't spam" rule).
     */
    private static function notifyIfLowStockCrossed(InventoryItem $item, InventoryStore $store, int $oldQuantity, int $newQuantity): void
    {
        $crossed = $item->reorder_level > 0 && $oldQuantity > $item->reorder_level && $newQuantity <= $item->reorder_level;
        if (! $crossed) {
            return;
        }

        $recipients = User::where('school_id', $item->school_id)
            ->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $store->branch_id))
            ->whereHas('permissions', fn ($q) => $q->whereIn('name', ['inventory-manage', 'stock-receive']))
            ->get();

        Notification::send($recipients, new LowStockAlert($item, $store, $newQuantity));
    }

    /**
     * A locked StockBalance row for (item, store), creating it first (quantity 0) if it never existed.
     * The existence check + insertOrIgnore happen WITHOUT a lock (a locking read on a row that doesn't exist
     * yet takes a gap lock, and two simultaneous first-movements on the same item deadlock on the insert) --
     * exactly the same two-step reasoning as NumberSequence::next().
     */
    private static function lockedBalance(int $itemId, int $storeId): StockBalance
    {
        if (! StockBalance::where('item_id', $itemId)->where('store_id', $storeId)->exists()) {
            DB::table('stock_balances')->insertOrIgnore([
                'item_id' => $itemId,
                'store_id' => $storeId,
                'quantity' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return StockBalance::where('item_id', $itemId)->where('store_id', $storeId)->lockForUpdate()->firstOrFail();
    }
}
