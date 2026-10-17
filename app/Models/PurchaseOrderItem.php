<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One ordered line, always tied back to the specific requested line it fulfils -- no free-text description
 * of its own, same reasoning as `QuotationItem`. This is what Goods Receipt matches received quantities
 * against for the three-way check (request vs. order vs. receipt). */
class PurchaseOrderItem extends Model
{
    protected $fillable = ['purchase_order_id', 'purchase_request_item_id', 'quantity', 'unit_price', 'tax_amount', 'total'];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function purchaseRequestItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequestItem::class);
    }

    public function goodsReceiptItems(): HasMany
    {
        return $this->hasMany(GoodsReceiptItem::class);
    }

    /** Never cached -- summed live across every receipt against this line, so it always reflects reality
     * even though a GoodsReceipt is itself never edited or removed. */
    public function receivedQuantity(): int
    {
        return (int) $this->goodsReceiptItems()->sum('quantity_received');
    }

    public function supplierBillItems(): HasMany
    {
        return $this->hasMany(SupplierBillItem::class);
    }

    /** Never cached -- the three-way match's "bill" side: how much of this line has already been billed,
     * so a new bill line can never exceed what was actually received for it. */
    public function billedQuantity(): int
    {
        return (int) $this->supplierBillItems()->sum('quantity');
    }
}
