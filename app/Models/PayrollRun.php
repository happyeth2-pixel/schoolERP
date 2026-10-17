<?php

namespace App\Models;

use App\Exceptions\DeletionBlockedException;
use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One branch's payroll for one calendar month -- HR Module Phase F. `draft` can be deleted outright (its
 * payslips are only a working calculation, nothing has been promised to anyone yet); once `finalized` it is
 * immutable, the same "immutable once real" rule `EmploymentContract`/`LeaveRequest`/`PerformanceReview`
 * already use -- and, confirmed via AskUserQuestion, finalizing is also the moment it posts to the
 * accounting engine (best-effort, same as every other money-moving module).
 */
class PayrollRun extends Model
{
    use Audited;

    public const STATUSES = ['draft', 'finalized'];

    protected $fillable = ['school_id', 'branch_id', 'year', 'month', 'status', 'generated_by', 'finalized_by', 'finalized_at', 'journal_entry_id'];

    protected function casts(): array
    {
        return [
            'finalized_at' => 'datetime',
        ];
    }

    public static function booted(): void
    {
        static::deleting(function (self $run) {
            if ($run->status === 'finalized') {
                throw new DeletionBlockedException('A finalized payroll run cannot be removed.');
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

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function finalizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    public function label(): string
    {
        return \Carbon\Carbon::createFromDate($this->year, $this->month, 1)->translatedFormat('F Y');
    }

    public function auditLabel(): string
    {
        return "{$this->branch->name} -- {$this->label()}";
    }

    public function auditAction(array $changed): string
    {
        return in_array('status', $changed, true) ? 'payroll_run.finalized' : 'payroll_run.updated';
    }
}
