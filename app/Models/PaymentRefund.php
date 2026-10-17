<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\NeverHardDeleted;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A refund (money handed back) or reversal (a payment that should not have counted) of
 * a VERIFIED payment. It is a new record beside the payment: the payment itself is never
 * edited or deleted. Append-only and audited.
 */
class PaymentRefund extends Model
{
    use Audited;
    use NeverHardDeleted;

    public const KINDS = [
        'refund' => 'Refund — money returned to the payer',
        'reversal' => 'Reversal — the payment should not have counted (duplicate, wrong invoice, never received)',
    ];

    protected $fillable = ['payment_id', 'amount', 'kind', 'reason', 'reference', 'refunded_by', 'refunded_at'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'refunded_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new \App\Exceptions\DeletionBlockedException('Refund records cannot be changed. Record a further correction instead.');
        });
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function refundedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'refunded_by');
    }

    /** The journal entry posted when this refund/reversal was recorded. See App\Services\AccountingPostings. */
    public function journalEntries(): MorphMany
    {
        return $this->morphMany(JournalEntry::class, 'source');
    }

    public function auditLabel(): string
    {
        return ucfirst($this->kind).' of '.number_format((float) $this->amount, 2).' on payment #'.$this->payment_id;
    }

    public function auditReason(array $changed): ?string
    {
        return $this->reason;
    }
}
