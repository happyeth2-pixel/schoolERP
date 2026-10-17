<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One line: either a catalog item (item_id set) or a free-text service/non-inventory line (item_id null)
 * -- the brief explicitly asks the module to support both. */
class PurchaseRequestItem extends Model
{
    protected $fillable = ['purchase_request_id', 'item_id', 'description', 'quantity', 'estimated_unit_price', 'estimated_total', 'notes'];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'estimated_unit_price' => 'decimal:2',
            'estimated_total' => 'decimal:2',
        ];
    }

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'item_id');
    }

    public function purchaseOrderItems(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    /** Never cached -- how much of this line has already been placed on a non-cancelled purchase order,
     * summed live so a line can never be over-ordered even when split across more than one supplier. */
    public function orderedQuantity(): int
    {
        return (int) $this->purchaseOrderItems()
            ->whereHas('purchaseOrder', fn ($q) => $q->where('status', '!=', 'cancelled'))
            ->sum('quantity');
    }

    /** Never cached -- how much of this line has actually arrived, summed live across every purchase
     * order's goods receipts (a GoodsReceipt is never cancelled/removed, so no status filter is needed here
     * the way `orderedQuantity()` needs one). This is the "receipt" side of the three-way check. */
    public function receivedQuantity(): int
    {
        return (int) GoodsReceiptItem::whereHas('purchaseOrderItem', fn ($q) => $q->where('purchase_request_item_id', $this->id))
            ->sum('quantity_received');
    }
}
