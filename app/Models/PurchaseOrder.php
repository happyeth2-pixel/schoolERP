<?php

namespace App\Models;

use App\Exceptions\DeletionBlockedException;
use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A formal order to ONE supplier, built from an approved purchase request -- optionally priced from a
 * selected quotation, but a quotation is never required. Totals are never cached: always summed live from
 * the items, the same rule every other document in this module follows.
 */
class PurchaseOrder extends Model
{
    use Audited;
    use SoftDeletes;

    public const STATUSES = ['draft', 'sent', 'partially_received', 'received', 'cancelled'];

    protected $fillable = [
        'school_id', 'branch_id', 'purchase_request_id', 'quotation_id', 'supplier_id', 'number', 'status',
        'order_date', 'expected_delivery_date', 'delivery_info', 'notes', 'created_by',
        'sent_at', 'cancelled_at', 'cancellation_reason',
    ];

    protected function casts(): array
    {
        return ['order_date' => 'date', 'expected_delivery_date' => 'date', 'sent_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::deleting(function (PurchaseOrder $po) {
            if ($po->status !== 'draft') {
                throw new DeletionBlockedException('Only a draft (never sent) purchase order can be removed -- cancel it instead.');
            }
        });
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class);
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function goodsReceipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class);
    }

    /** Never cached -- summed live from the items, same rule every other document here follows. */
    public function total(): float
    {
        return (float) $this->items->sum('total');
    }

    /** Recomputes status from live received quantities after a goods receipt -- never left at 'sent' once
     * anything has arrived. Only called from 'sent' or 'partially_received'; never touches draft/cancelled. */
    public function refreshReceiptStatus(): void
    {
        $items = $this->items;
        $fullyReceived = $items->every(fn (PurchaseOrderItem $i) => $i->receivedQuantity() >= $i->quantity);

        $this->update(['status' => $fullyReceived ? 'received' : 'partially_received']);
    }

    public function auditLabel(): string
    {
        return "Purchase order {$this->number}";
    }

    public function auditAction(array $changed): string
    {
        return 'purchase_order.'.(in_array('status', $changed, true) ? 'status_changed' : 'updated');
    }

    public function auditReason(array $changed): ?string
    {
        return $this->status === 'cancelled' ? $this->cancellation_reason : null;
    }
}
