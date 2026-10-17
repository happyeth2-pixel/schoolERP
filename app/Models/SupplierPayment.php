<?php

namespace App\Models;

use App\Exceptions\DeletionBlockedException;
use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Paying a supplier bill -- the mirror of `Payment` on the receivable side, but always born "confirmed":
 * there is no external party submitting proof for money going out, so unlike `Payment` there is no
 * pending/verify step. Append-only and audited, the same ledger-adjacent immutability as every other
 * financial record in this app -- a mistake is corrected with a new record (a SupplierPaymentReversal),
 * never by editing this one.
 */
class SupplierPayment extends Model
{
    use Audited;

    protected $fillable = ['school_id', 'branch_id', 'supplier_bill_id', 'amount', 'method', 'reference_number', 'gl_cash_account_id', 'notes', 'recorded_by', 'paid_date'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'paid_date' => 'date'];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new DeletionBlockedException('A supplier payment cannot be changed once recorded.');
        });

        static::deleting(function () {
            throw new DeletionBlockedException('A supplier payment cannot be removed -- it is part of the payment history.');
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

    public function supplierBill(): BelongsTo
    {
        return $this->belongsTo(SupplierBill::class);
    }

    public function glCashAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'gl_cash_account_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function journalEntries(): MorphMany
    {
        return $this->morphMany(JournalEntry::class, 'source');
    }

    public function reversals(): HasMany
    {
        return $this->hasMany(SupplierPaymentReversal::class);
    }

    /** What has already been reversed/refunded off this payment -- never cached. */
    public function reversedAmount(): float
    {
        $this->loadMissing('reversals');

        return (float) $this->reversals->sum('amount');
    }

    /** What this payment still actually counts for, after reversals -- the figure SupplierBill::paidAmount()
     * sums, exactly mirroring how Payment/Invoice treat a refunded amount as no longer collected. */
    public function netAmount(): float
    {
        return max(0, \App\Services\Finance::toCents((float) $this->amount) - \App\Services\Finance::toCents($this->reversedAmount())) / 100;
    }

    public function auditLabel(): string
    {
        return 'Payment of '.number_format((float) $this->amount, 2)." on bill #{$this->supplier_bill_id}";
    }
}
