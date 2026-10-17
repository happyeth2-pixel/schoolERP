<?php

namespace App\Models;

use App\Exceptions\DeletionBlockedException;
use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * The header row of a double-entry posting. A draft entry may be freely edited; once posted it is
 * immutable (see booted() below) -- the same append-only philosophy as StockMovement/PaymentRefund, the
 * strictest pattern already used in this app for records other data depends on. A mistake is corrected
 * with reverse(), never an edit. `status` only ever really has two meaningful values in practice --
 * `draft` and `posted` -- "has this been reversed" is never stored, only derived from
 * `reversed_by_entry_id !== null`, the same "never cache a derived fact" rule every other figure in this
 * app already follows.
 *
 * All posting/balancing/period-lock logic lives in App\Services\Ledger, never here -- this model only
 * protects its own immutability once posted.
 */
class JournalEntry extends Model
{
    use Audited;

    protected $fillable = [
        'school_id', 'branch_id', 'journal_id', 'accounting_period_id', 'entry_number', 'entry_date',
        'reference', 'description', 'source_module', 'source_type', 'source_id', 'status',
        'created_by', 'posted_by', 'posted_at', 'reverses_entry_id', 'reversed_by_entry_id',
    ];

    protected function casts(): array
    {
        return ['entry_date' => 'date', 'posted_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (JournalEntry $entry) {
            $wasPosted = $entry->getOriginal('status') === 'posted';
            $onlyLinkingReversal = array_keys($entry->getDirty()) === ['reversed_by_entry_id'];
            if ($wasPosted && ! $onlyLinkingReversal) {
                throw new DeletionBlockedException('A posted journal entry cannot be changed -- record a reversing entry instead.');
            }
        });

        static::deleting(function (JournalEntry $entry) {
            if ($entry->status !== 'draft') {
                throw new DeletionBlockedException('Only a draft journal entry can be removed -- a posted one is reversed instead.');
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

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    public function accountingPeriod(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_entry_id');
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_by_entry_id');
    }

    public function isPosted(): bool
    {
        return $this->status === 'posted';
    }

    public function isReversed(): bool
    {
        return $this->reversed_by_entry_id !== null;
    }

    /** Never cached -- summed live from the lines, same rule every other document in this app follows. */
    public function totalDebit(): float
    {
        return (float) $this->lines->sum('debit');
    }

    public function totalCredit(): float
    {
        return (float) $this->lines->sum('credit');
    }

    public function auditLabel(): string
    {
        return "Journal entry {$this->entry_number}";
    }

    public function auditAction(array $changed): string
    {
        return 'journal_entry.'.(in_array('status', $changed, true) || in_array('reversed_by_entry_id', $changed, true) ? 'status_changed' : 'updated');
    }
}
