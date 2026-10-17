<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Concerns\NeverHardDeleted;
use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Payment extends Model
{
    use Audited;

    use NeverHardDeleted;

    use HasFactory;

    protected $fillable = [
        'invoice_id', 'amount', 'method', 'reference_number', 'proof_path',
        'submitted_by', 'status', 'channel', 'paid_by_name', 'note', 'verified_by', 'verified_at', 'rejection_reason', 'receipt_number',
        'gl_cash_account_id',
    ];

    public const CHANNEL_PORTAL = 'portal';

    public const CHANNEL_COUNTER = 'counter';

    public function isCounter(): bool
    {
        return $this->channel === self::CHANNEL_COUNTER;
    }

    /** A payment recorded at the school desk writes its own plain 'payment.recorded' entry instead of a raw creation row. */
    public function auditsCreation(): bool
    {
        return ! $this->isCounter();
    }

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function refunds(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PaymentRefund::class);
    }

    /** What can still be refunded/reversed: the payment's amount minus what already has been. */
    public function refundableAmount(): float
    {
        $this->loadMissing('refunds');

        return max(0, \App\Services\Finance::toCents($this->amount) - \App\Services\Finance::toCents($this->refunds->sum('amount'))) / 100;
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function glCashAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'gl_cash_account_id');
    }

    /** The journal entry posted when this payment was verified. See App\Services\AccountingPostings. */
    public function journalEntries(): MorphMany
    {
        return $this->morphMany(JournalEntry::class, 'source');
    }

    public function auditLabel(): string
    {
        return 'Payment #'.$this->id.' of '.number_format((float) $this->amount, 2);
    }

    public function auditAction(array $changed): string
    {
        if (in_array('status', $changed, true)) {
            return match ($this->status) {
                'verified' => 'payment.verified',
                'rejected' => 'payment.rejected',
                default => 'payment.status_changed',
            };
        }

        return 'payment.updated';
    }

    public function auditReason(array $changed): ?string
    {
        return $this->rejection_reason;
    }
}
