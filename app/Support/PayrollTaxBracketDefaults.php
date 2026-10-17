<?php

namespace App\Support;

use App\Models\School;

/**
 * The researched Ethiopian progressive income-tax schedule -- Income Tax Proclamation (Amendment) No.
 * 1395/2025, effective 8 July 2025. Shared by the migration that seeds every pre-existing school AND
 * `install()` here, which any code creating a new school (including tests) should call explicitly -- the
 * same "not automatic, but one call away" shape `ChartOfAccountsTemplate::install()` already uses.
 *
 * UNVERIFIED AGAINST CURRENT LAW -- flagged throughout the Payroll Settings UI, per the user's own explicit
 * instruction from the start of the HR module initiative. A school's accountant should confirm these
 * figures before relying on them for real pay.
 */
class PayrollTaxBracketDefaults
{
    /** @return list<array{min:float,max:?float,rate:float,deduction:float}> */
    public static function brackets(): array
    {
        return [
            ['min' => 0, 'max' => 2000, 'rate' => 0.00, 'deduction' => 0],
            ['min' => 2000.01, 'max' => 4000, 'rate' => 0.15, 'deduction' => 300],
            ['min' => 4000.01, 'max' => 7000, 'rate' => 0.20, 'deduction' => 500],
            ['min' => 7000.01, 'max' => 10000, 'rate' => 0.25, 'deduction' => 850],
            ['min' => 10000.01, 'max' => 14000, 'rate' => 0.30, 'deduction' => 1350],
            ['min' => 14000.01, 'max' => null, 'rate' => 0.35, 'deduction' => 2050],
        ];
    }

    /** No-op if the school already has any brackets -- safe to call unconditionally. */
    public static function install(School $school): void
    {
        if ($school->payrollTaxBrackets()->exists()) {
            return;
        }

        foreach (self::brackets() as $bracket) {
            $school->payrollTaxBrackets()->create([
                'min_amount' => $bracket['min'],
                'max_amount' => $bracket['max'],
                'rate' => $bracket['rate'],
                'deduction' => $bracket['deduction'],
            ]);
        }
    }
}
