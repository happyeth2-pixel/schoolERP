<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One progressive income-tax bracket for a school -- HR Module Phase F. Tax within a bracket is
 * `(taxable_income * rate) - deduction`, the standard subtraction-method presentation used on Ethiopian
 * payslips. Seeded with the current published schedule (see the migration that creates this table) but
 * fully editable -- a school's accountant should verify these against current law, flagged throughout the
 * UI, never silently trusted as permanently correct.
 */
class PayrollTaxBracket extends Model
{
    use Audited;

    protected $fillable = ['school_id', 'min_amount', 'max_amount', 'rate', 'deduction'];

    protected function casts(): array
    {
        return [
            'min_amount' => 'decimal:2',
            'max_amount' => 'decimal:2',
            'rate' => 'decimal:4',
            'deduction' => 'decimal:2',
        ];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function auditLabel(): string
    {
        $max = $this->max_amount !== null ? number_format((float) $this->max_amount, 2) : 'and above';

        return "{$this->min_amount}-{$max} @ ".((float) $this->rate * 100).'%';
    }
}
