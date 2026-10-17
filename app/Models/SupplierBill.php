<?php

namespace App\Models;

use App\Exceptions\DeletionBlockedException;
use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * The vendor invoice itself -- exists fully formed the moment it's created, the same shape as `Invoice`:
 * no draft state, no stored status or total. `total()`/`paidAmount()`/`balance()` are always summed live
 * from the items and payments, never cached, the same rule every financial document in this app follows.
 * `purchase_order_id` is nullable (a bill doesn't require a PO), but each line that references one is
 * checked against what was actually RECEIVED for it -- the three-way match.
 */
class SupplierBill extends Model
{
    use Audited;

    protected $fillable = ['school_id', 'branch_id', 'supplier_id', 'purchase_order_id', 'bill_number', 'supplier_reference', 'bill_date', 'due_date', 'notes', 'created_by'];

    protected function casts(): array
    {
        return ['bill_date' => 'date', 'due_date' => 'date'];
    }

    protected static function booted(): void
    {
        static::deleting(function (SupplierBill $bill) {
            if ($bill->payments()->exists()) {
                throw new DeletionBlockedException('A supplier bill that has been paid against cannot be removed.');
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

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SupplierBillItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class);
    }

    /** Journal entries posted against this bill -- recording it, and (via SupplierPayment) paying it. */
    public function journalEntries(): MorphMany
    {
        return $this->morphMany(JournalEntry::class, 'source');
    }

    /** Never cached -- summed live from the items. */
    public function total(): float
    {
        return (float) $this->items->sum('total');
    }

    /** Never cached -- summed live from the payments, net of any reversal/refund against each one (a
     * reversed payment no longer counts, the same rule Payment/Invoice already apply on the receivable
     * side). */
    public function paidAmount(): float
    {
        $this->loadMissing('payments.reversals');

        return (float) $this->payments->sum(fn (SupplierPayment $p) => $p->netAmount());
    }

    public function balance(): float
    {
        return max(0, round($this->total() - $this->paidAmount(), 2));
    }

    public function auditLabel(): string
    {
        return "Supplier bill {$this->bill_number}";
    }
}
