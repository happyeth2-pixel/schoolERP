<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One billed line. When tied to a purchase order line (`purchase_order_item_id`), its quantity is checked
 * against what was actually RECEIVED for that line, not merely ordered -- the three-way match (order vs.
 * receipt vs. bill), enforced in the controller at creation time. */
class SupplierBillItem extends Model
{
    protected $fillable = ['supplier_bill_id', 'purchase_order_item_id', 'expense_account_id', 'description', 'quantity', 'unit_price', 'tax_amount', 'total'];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    public function supplierBill(): BelongsTo
    {
        return $this->belongsTo(SupplierBill::class);
    }

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class);
    }

    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'expense_account_id');
    }

    /** Never cached -- summed live across every bill line against this PO line, so a line can never be
     * billed for more than it, in total, received -- even split across more than one bill. */
    public static function billedQuantityFor(int $purchaseOrderItemId): int
    {
        return (int) self::where('purchase_order_item_id', $purchaseOrderItemId)->sum('quantity');
    }
}
