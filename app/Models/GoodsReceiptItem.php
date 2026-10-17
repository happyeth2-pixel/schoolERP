<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One received line, always tied back to the purchase order line it fulfils -- no free-text description of
 * its own, same reasoning as `QuotationItem`/`PurchaseOrderItem`. */
class GoodsReceiptItem extends Model
{
    protected $fillable = ['goods_receipt_id', 'purchase_order_item_id', 'quantity_received', 'notes'];

    protected function casts(): array
    {
        return ['quantity_received' => 'integer'];
    }

    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class);
    }
}
