<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\NeverHardDeleted;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A refund (money handed back by the supplier) or reversal (a payment that should not have counted) of a
 * SupplierPayment. A new record beside the payment: the payment itself is never edited or deleted. The
 * exact mirror of App\Models\PaymentRefund on the procurement side.
 */
class SupplierPaymentReversal extends Model
{
    use Audited;
    use NeverHardDeleted;

    public const KINDS = [
        'refund' => 'Refund — money returned by the supplier',
        'reversal' => 'Reversal — the payment should not have counted (duplicate, wrong bill, sent in error)',
    ];

    protected $fillable = ['supplier_payment_id', 'amount', 'kind', 'reason', 'reference', 'reversed_by', 'reversed_at'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'reversed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new \App\Exceptions\DeletionBlockedException('Reversal records cannot be changed. Record a further correction instead.');
        });
    }

    public function supplierPayment(): BelongsTo
    {
        return $this->belongsTo(SupplierPayment::class);
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    /** The journal entry posted when this refund/reversal was recorded. See App\Services\AccountingPostings. */
    public function journalEntries(): MorphMany
    {
        return $this->morphMany(JournalEntry::class, 'source');
    }

    public function auditLabel(): string
    {
        return ucfirst($this->kind).' of '.number_format((float) $this->amount, 2).' on supplier payment #'.$this->supplier_payment_id;
    }

    public function auditReason(array $changed): ?string
    {
        return $this->reason;
    }
}
