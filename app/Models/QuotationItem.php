<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One priced line, quoting against a specific line of the purchase request -- what makes comparing several
 * suppliers' quotations for the SAME request meaningful. */
class QuotationItem extends Model
{
    protected $fillable = ['quotation_id', 'purchase_request_item_id', 'quantity', 'unit_price', 'tax_amount', 'total'];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function purchaseRequestItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequestItem::class);
    }
}
