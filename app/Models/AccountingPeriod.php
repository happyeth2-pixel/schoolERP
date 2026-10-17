<?php

namespace App\Models;

use App\Exceptions\DeletionBlockedException;
use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A journal entry may only ever be posted while its period is `open` (enforced in App\Services\Ledger).
 * Closing is the ordinary action an accountant takes once a month/term/year is done; reopening is a
 * separate, stronger permission (accounting-period-reopen) since undoing a close is rarer and riskier. */
class AccountingPeriod extends Model
{
    use Audited;

    public const STATUSES = ['open', 'closed'];

    protected $fillable = ['school_id', 'name', 'start_date', 'end_date', 'status', 'closed_by', 'closed_at', 'reopened_by', 'reopened_at'];

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date', 'closed_at' => 'datetime', 'reopened_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::deleting(function (AccountingPeriod $period) {
            if ($period->entries()->exists()) {
                throw new DeletionBlockedException('A period with journal entries cannot be removed.');
            }
        });
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(JournalEntry::class);
    }

    public function auditLabel(): string
    {
        return "Accounting period {$this->name}";
    }

    public function auditAction(array $changed): string
    {
        return 'accounting_period.'.(in_array('status', $changed, true) ? 'status_changed' : 'updated');
    }
}
