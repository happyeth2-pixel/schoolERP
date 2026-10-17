<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One employee's computed pay for one `PayrollRun` -- HR Module Phase F. Every figure is stored as a
 * snapshot at generation time, never re-derived later: a contract's salary changing after the fact must
 * never silently rewrite a payslip that already exists. Deletion follows its parent run entirely (no
 * independent guard here) -- see `PayrollRun::booted()`.
 */
class Payslip extends Model
{
    use Audited;

    protected $fillable = [
        'payroll_run_id', 'employee_id', 'basic_salary', 'gross_pay', 'taxable_income',
        'income_tax', 'employee_pension', 'employer_pension', 'net_pay',
    ];

    protected function casts(): array
    {
        return [
            'basic_salary' => 'decimal:2',
            'gross_pay' => 'decimal:2',
            'taxable_income' => 'decimal:2',
            'income_tax' => 'decimal:2',
            'employee_pension' => 'decimal:2',
            'employer_pension' => 'decimal:2',
            'net_pay' => 'decimal:2',
        ];
    }

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function auditLabel(): string
    {
        return "{$this->employee->full_name} -- {$this->payrollRun->label()}";
    }

    public function auditsCreation(): bool
    {
        // One run can create dozens of payslips in a single pass; the run's own audit entry already
        // captures that a payroll run happened, and later DB-level guards block editing a finalized one --
        // so per-payslip "created" noise would never actually inform anyone.
        return false;
    }
}
