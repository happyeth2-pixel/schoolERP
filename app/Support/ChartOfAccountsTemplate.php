<?php

namespace App\Support;

use App\Models\Account;
use App\Models\Journal;
use App\Models\School;
use Illuminate\Support\Facades\DB;

/**
 * A sensible starting chart of accounts + the seven common journals -- NOT one hardcoded chart imposed on
 * every school. `install()` is a one-time, explicit, user-triggered action (never run automatically by a
 * migration or seeder) that a school runs once and then edits freely: rename accounts, add new ones,
 * deactivate ones it doesn't need. Re-running is a no-op for anything that already exists (matched by
 * code), so it's safe to call again after a school adds accounts of its own.
 *
 * The accounts flagged `system: true` here are the ones the posting engine (App\Services\Ledger and the
 * later Invoice/Payment/PurchaseOrder integrations) relies on by ROLE, wired up on School via the
 * default_*_account_id columns -- never by assuming a fixed code or name, since a school can rename or
 * recode anything non-system afterward.
 */
class ChartOfAccountsTemplate
{
    /**
     * @return list<array{code:string,name:string,type:string,header?:bool,system?:string,children?:array}>
     */
    public static function accounts(): array
    {
        return [
            ['code' => '1000', 'name' => 'Assets', 'type' => 'asset', 'header' => true, 'children' => [
                ['code' => '1100', 'name' => 'Cash', 'type' => 'asset'],
                ['code' => '1150', 'name' => 'Undeposited Funds', 'type' => 'asset', 'system' => 'cash'],
                ['code' => '1200', 'name' => 'Bank', 'type' => 'asset'],
                ['code' => '1300', 'name' => 'Accounts Receivable', 'type' => 'asset', 'system' => 'ar'],
            ]],
            ['code' => '2000', 'name' => 'Liabilities', 'type' => 'liability', 'header' => true, 'children' => [
                ['code' => '2100', 'name' => 'Accounts Payable', 'type' => 'liability', 'system' => 'ap'],
                ['code' => '2200', 'name' => 'Income Tax Payable', 'type' => 'liability', 'system' => 'tax_payable'],
                ['code' => '2300', 'name' => 'Pension Payable', 'type' => 'liability', 'system' => 'pension_payable'],
                ['code' => '2400', 'name' => 'Net Salary Payable', 'type' => 'liability', 'system' => 'salary_payable'],
            ]],
            ['code' => '3000', 'name' => 'Equity / Fund', 'type' => 'equity', 'header' => true, 'children' => [
                ['code' => '3100', 'name' => 'Retained Earnings', 'type' => 'equity'],
            ]],
            ['code' => '4000', 'name' => 'Revenue', 'type' => 'revenue', 'header' => true, 'children' => [
                ['code' => '4100', 'name' => 'Tuition Fees', 'type' => 'revenue', 'system' => 'revenue'],
                ['code' => '4200', 'name' => 'Registration Fees', 'type' => 'revenue'],
                ['code' => '4910', 'name' => 'Fee Discounts', 'type' => 'revenue', 'system' => 'discount'],
                ['code' => '4920', 'name' => 'Fee Waivers', 'type' => 'revenue', 'system' => 'waiver'],
            ]],
            ['code' => '5000', 'name' => 'Expenses', 'type' => 'expense', 'header' => true, 'children' => [
                ['code' => '5100', 'name' => 'Salaries', 'type' => 'expense', 'system' => 'salary_expense'],
                ['code' => '5110', 'name' => 'Pension Expense (Employer)', 'type' => 'expense', 'system' => 'pension_expense'],
                ['code' => '5200', 'name' => 'Utilities', 'type' => 'expense'],
                ['code' => '5300', 'name' => 'Supplies', 'type' => 'expense'],
                ['code' => '5900', 'name' => 'General Expenses', 'type' => 'expense', 'system' => 'expense'],
            ]],
        ];
    }

    /** @return list<array{code:string,name:string,type:string}> */
    public static function journals(): array
    {
        return [
            ['code' => 'CASH', 'name' => 'Cash', 'type' => 'cash'],
            ['code' => 'BANK', 'name' => 'Bank', 'type' => 'bank'],
            ['code' => 'SALES', 'name' => 'Sales', 'type' => 'sales'],
            ['code' => 'PURCH', 'name' => 'Purchases', 'type' => 'purchase'],
            ['code' => 'GEN', 'name' => 'General', 'type' => 'general'],
            ['code' => 'AR', 'name' => 'Receivable', 'type' => 'receivable'],
            ['code' => 'AP', 'name' => 'Payable', 'type' => 'payable'],
            ['code' => 'PAYROLL', 'name' => 'Payroll', 'type' => 'general'],
        ];
    }

    public static function isInstalled(School $school): bool
    {
        return $school->accounts()->exists();
    }

    /** Creates every account/journal from the template that doesn't already exist (matched by code), and
     * wires the school's default_*_account_id columns to the `system`-flagged accounts. Safe to call more
     * than once. */
    public static function install(School $school): void
    {
        DB::transaction(function () use ($school) {
            $systemAccounts = [];

            foreach (self::accounts() as $top) {
                $parent = self::findOrCreateAccount($school, $top, null, $systemAccounts);
                foreach ($top['children'] ?? [] as $child) {
                    self::findOrCreateAccount($school, $child, $parent, $systemAccounts);
                }
            }

            foreach (self::journals() as $journal) {
                Journal::firstOrCreate(
                    ['school_id' => $school->id, 'code' => $journal['code']],
                    ['name' => $journal['name'], 'type' => $journal['type'], 'is_system' => true, 'is_active' => true],
                );
            }

            $school->update([
                'default_ar_account_id' => $systemAccounts['ar'] ?? $school->default_ar_account_id,
                'default_ap_account_id' => $systemAccounts['ap'] ?? $school->default_ap_account_id,
                'default_cash_account_id' => $systemAccounts['cash'] ?? $school->default_cash_account_id,
                'default_revenue_account_id' => $systemAccounts['revenue'] ?? $school->default_revenue_account_id,
                'default_expense_account_id' => $systemAccounts['expense'] ?? $school->default_expense_account_id,
                'default_discount_account_id' => $systemAccounts['discount'] ?? $school->default_discount_account_id,
                'default_waiver_account_id' => $systemAccounts['waiver'] ?? $school->default_waiver_account_id,
                'default_salary_expense_account_id' => $systemAccounts['salary_expense'] ?? $school->default_salary_expense_account_id,
                'default_pension_expense_account_id' => $systemAccounts['pension_expense'] ?? $school->default_pension_expense_account_id,
                'default_tax_payable_account_id' => $systemAccounts['tax_payable'] ?? $school->default_tax_payable_account_id,
                'default_pension_payable_account_id' => $systemAccounts['pension_payable'] ?? $school->default_pension_payable_account_id,
                'default_salary_payable_account_id' => $systemAccounts['salary_payable'] ?? $school->default_salary_payable_account_id,
            ]);
        });
    }

    private static function findOrCreateAccount(School $school, array $spec, ?Account $parent, array &$systemAccounts): Account
    {
        $account = Account::withTrashed()->firstOrCreate(
            ['school_id' => $school->id, 'code' => $spec['code']],
            [
                'parent_id' => $parent?->id,
                'name' => $spec['name'],
                'type' => $spec['type'],
                'is_header' => $spec['header'] ?? false,
                'is_system' => isset($spec['system']),
                'is_active' => true,
            ],
        );

        if (isset($spec['system'])) {
            $systemAccounts[$spec['system']] = $account->id;
            // A re-run can assign a system role to an account that already existed from an earlier
            // install (e.g. Phase F wiring the pre-existing "Salaries" account by role for the first
            // time) -- promote it so the deletion guard actually protects it, same as a freshly-created one.
            if (! $account->is_system) {
                $account->update(['is_system' => true]);
            }
        }

        return $account;
    }
}
