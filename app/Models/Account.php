<?php

namespace App\Models;

use App\Exceptions\DeletionBlockedException;
use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One line of a school's own chart of accounts -- never a single hardcoded chart for every school (see
 * App\Support\ChartOfAccountsTemplate for the default a school installs once and then edits freely).
 * `is_header` marks a pure grouping account ("1000 Assets") that a journal line may never post to; only a
 * leaf/detail account can receive a posting (enforced in App\Services\Ledger, not here). `is_system`
 * protects the handful of accounts the posting engine relies on by ROLE (see schools.default_*_account_id)
 * from being deleted or retyped out from under it.
 */
class Account extends Model
{
    use Audited;
    use SoftDeletes;

    public const TYPES = ['asset', 'liability', 'equity', 'revenue', 'expense'];

    public const DEBIT_NORMAL_TYPES = ['asset', 'expense'];

    protected $fillable = ['school_id', 'branch_id', 'parent_id', 'code', 'name', 'type', 'normal_balance', 'is_header', 'is_system', 'is_active'];

    protected function casts(): array
    {
        return ['is_header' => 'boolean', 'is_system' => 'boolean', 'is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::creating(function (Account $account) {
            $account->normal_balance ??= in_array($account->type, self::DEBIT_NORMAL_TYPES, true) ? 'debit' : 'credit';
        });

        static::deleting(function (Account $account) {
            if ($account->is_system) {
                throw new DeletionBlockedException('A system account cannot be removed -- it is relied on by the posting engine.');
            }
            if ($account->children()->exists()) {
                throw new DeletionBlockedException('An account with child accounts cannot be removed -- move or remove its children first.');
            }
            if ($account->lines()->exists()) {
                throw new DeletionBlockedException('An account that already has journal activity cannot be removed -- deactivate it instead.');
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

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class);
    }

    /** Never cached -- summed live from posted journal lines only, signed so it reads naturally for this
     * account's own normal side (a debit-normal account's balance is debit-minus-credit, and vice versa). */
    public function balance(?string $asOfDate = null): float
    {
        $query = $this->lines()->whereHas('journalEntry', fn ($q) => $q->where('status', 'posted')
            ->when($asOfDate, fn ($q2) => $q2->where('entry_date', '<=', $asOfDate)));

        $debit = (float) (clone $query)->sum('debit');
        $credit = (float) (clone $query)->sum('credit');

        return $this->normal_balance === 'debit' ? $debit - $credit : $credit - $debit;
    }

    /** Movement WITHIN a date range only (never cumulative since inception) -- for the Income Statement,
     * where revenue/expense accounts are read per period rather than as a running balance. */
    public function movementBetween(string $from, string $to): float
    {
        $query = $this->lines()->whereHas('journalEntry', fn ($q) => $q->where('status', 'posted')
            ->whereBetween('entry_date', [$from, $to]));

        $debit = (float) (clone $query)->sum('debit');
        $credit = (float) (clone $query)->sum('credit');

        return $this->normal_balance === 'debit' ? $debit - $credit : $credit - $debit;
    }

    public function auditLabel(): string
    {
        return "{$this->code} {$this->name}";
    }
}
