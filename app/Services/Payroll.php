<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\School;
use App\Models\User;

/**
 * The payroll calculation engine -- HR Module Phase F. Reads `EmploymentContract::salary_reference` (the
 * field Phase B deliberately left "reference-only, read by nothing" until this phase gave it a purpose).
 * Taxable income is the FULL basic salary (Ethiopian PAYE and pension are both computed on gross pay,
 * pension contributions are never deducted before tax -- confirmed by research, not assumed).
 */
class Payroll
{
    /** (taxable_income * bracket rate) - bracket deduction, for whichever bracket the income falls into. */
    public static function calculateTax(School $school, float $taxableIncome): float
    {
        $bracket = $school->payrollTaxBrackets()
            ->where('min_amount', '<=', $taxableIncome)
            ->where(fn ($q) => $q->whereNull('max_amount')->orWhere('max_amount', '>=', $taxableIncome))
            ->orderByDesc('min_amount')
            ->first();

        if (! $bracket) {
            return 0.0;
        }

        return max(0.0, round($taxableIncome * (float) $bracket->rate - (float) $bracket->deduction, 2));
    }

    /**
     * Creates a draft PayrollRun and one Payslip per eligible employee (active, in this branch, with a
     * current contract carrying a salary_reference). Ineligible employees are skipped with a plain reason,
     * never silently omitted -- the same "tell the user what was skipped and why" rule Invoice generation
     * already follows.
     *
     * @return array{run: PayrollRun, created: int, skipped: list<array{employee: string, reason: string}>}
     */
    public static function generate(School $school, Branch $branch, int $year, int $month, User $actor): array
    {
        $run = PayrollRun::create([
            'school_id' => $school->id, 'branch_id' => $branch->id, 'year' => $year, 'month' => $month,
            'status' => 'draft', 'generated_by' => $actor->id,
        ]);

        $skipped = [];
        $created = 0;

        Employee::where('school_id', $school->id)->where('branch_id', $branch->id)
            ->where('employment_status', 'active')->orderBy('full_name')->get()
            ->each(function (Employee $employee) use ($run, $school, &$skipped, &$created) {
                $contract = $employee->currentContract();
                if (! $contract) {
                    $skipped[] = ['employee' => $employee->full_name, 'reason' => 'No active employment contract.'];

                    return;
                }
                if ($contract->salary_reference === null) {
                    $skipped[] = ['employee' => $employee->full_name, 'reason' => 'Their employment contract has no salary on file.'];

                    return;
                }

                $basic = (float) $contract->salary_reference;
                $tax = self::calculateTax($school, $basic);
                $employeePension = round($basic * (float) $school->pension_employee_rate, 2);
                $employerPension = round($basic * (float) $school->pension_employer_rate, 2);

                $run->payslips()->create([
                    'employee_id' => $employee->id,
                    'basic_salary' => $basic,
                    'gross_pay' => $basic,
                    'taxable_income' => $basic,
                    'income_tax' => $tax,
                    'employee_pension' => $employeePension,
                    'employer_pension' => $employerPension,
                    'net_pay' => round($basic - $tax - $employeePension, 2),
                ]);
                $created++;
            });

        return ['run' => $run, 'created' => $created, 'skipped' => $skipped];
    }
}
