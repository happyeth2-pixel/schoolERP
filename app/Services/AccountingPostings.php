<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Journal;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\PayrollRun;
use App\Models\School;
use App\Models\SupplierBill;
use App\Models\SupplierPayment;
use App\Models\SupplierPaymentReversal;
use App\Models\User;

/**
 * Where every existing fee/payment event becomes a double-entry posting. Every method here is a thin,
 * best-effort wrapper: if the school hasn't installed a chart of accounts yet (no `default_ar_account_id`
 * etc. configured), it quietly does nothing and returns null -- Phase B is additive, and a school that
 * hasn't adopted accounting must see no change at all in its existing fee workflow. Callers (the
 * Invoice/Payment controllers) wrap these calls in their own try/catch on top of that, so that even an
 * unexpected posting failure (a missing period, a database hiccup) can NEVER block the real business action
 * money has already changed hands for -- the one rule that overrides everything else in this phase. Any gap
 * this creates between the fee system and the ledger is exactly what Phase D's reconciliation check exists
 * to catch.
 *
 * `Invoice`/`Payment`/`PaymentRefund` remain the single source of truth for what a family owes and paid;
 * these postings only mirror that reality into the ledger for aggregate reporting. Nothing here is ever
 * read back to answer "how much does this family owe" -- that question is still answered the same way it
 * always was, by `App\Services\Finance`.
 */
class AccountingPostings
{
    private static function generalJournal(School $school): ?Journal
    {
        return Journal::where('school_id', $school->id)->where('code', 'GEN')->where('is_active', true)->first();
    }

    private static function cashOrBankJournal(School $school, string $method): ?Journal
    {
        $code = $method === 'cash' ? 'CASH' : 'BANK';

        return Journal::where('school_id', $school->id)->where('code', $code)->where('is_active', true)->first()
            ?? self::generalJournal($school);
    }

    /** Dr Accounts Receivable / Cr Revenue -- one credit line per distinct revenue account across the
     * invoice's own lines (a fee or service maps to its own `revenue_account_id`, falling back to the
     * school's `default_revenue_account_id` when unmapped). */
    public static function postInvoiceIssued(Invoice $invoice, User $actor, ?string $entryDate = null): ?JournalEntry
    {
        $student = $invoice->student;
        $school = $student->school;
        if (! $school->default_ar_account_id) {
            return null;
        }

        $invoice->loadMissing('items.feeStructure', 'items.optionalService');

        $byAccount = [];
        foreach ($invoice->items as $item) {
            $source = $item->fee_structure_id ? $item->feeStructure : ($item->optional_service_id ? $item->optionalService : null);
            $accountId = $source?->revenue_account_id ?? $school->default_revenue_account_id;
            if (! $accountId) {
                continue;
            }
            $byAccount[$accountId] = ($byAccount[$accountId] ?? 0) + (float) $item->amount;
        }
        if ($byAccount === []) {
            return null;
        }

        $journal = Journal::where('school_id', $school->id)->where('code', 'SALES')->where('is_active', true)->first() ?? self::generalJournal($school);
        if (! $journal) {
            return null;
        }

        $total = round(array_sum($byAccount), 2);
        if ($total <= 0) {
            return null;
        }

        $lines = [['account_id' => $school->default_ar_account_id, 'debit' => $total, 'credit' => 0]];
        foreach ($byAccount as $accountId => $amount) {
            $lines[] = ['account_id' => $accountId, 'debit' => 0, 'credit' => round($amount, 2)];
        }

        return Ledger::createAndPost($school, [
            'branch_id' => $student->branch_id,
            'journal_id' => $journal->id,
            'entry_date' => $entryDate ?? now()->toDateString(),
            'reference' => $invoice->invoice_number,
            'description' => "Invoice {$invoice->invoice_number} issued to {$student->fullName()}",
            'source_module' => 'fee_billing',
            'source_type' => Invoice::class,
            'source_id' => $invoice->id,
        ], $lines, $actor);
    }

    /** Posts only the CHANGE in discount since it was last recorded -- an invoice's discount_amount is
     * mutable, so the caller passes the old and new values and only the delta is ever posted, never the
     * absolute new value (which would double-count every edit). */
    public static function postDiscountChanged(Invoice $invoice, float $oldDiscount, float $newDiscount, User $actor, ?string $entryDate = null): ?JournalEntry
    {
        $delta = round($newDiscount - $oldDiscount, 2);
        if (abs($delta) < 0.005) {
            return null;
        }

        $student = $invoice->student;
        $school = $student->school;
        if (! $school->default_ar_account_id || ! $school->default_discount_account_id) {
            return null;
        }

        $journal = self::generalJournal($school);
        if (! $journal) {
            return null;
        }

        $amount = abs($delta);
        $increased = $delta > 0;
        $lines = [
            ['account_id' => $increased ? $school->default_discount_account_id : $school->default_ar_account_id, 'debit' => $amount, 'credit' => 0],
            ['account_id' => $increased ? $school->default_ar_account_id : $school->default_discount_account_id, 'debit' => 0, 'credit' => $amount],
        ];

        return Ledger::createAndPost($school, [
            'branch_id' => $student->branch_id,
            'journal_id' => $journal->id,
            'entry_date' => $entryDate ?? now()->toDateString(),
            'reference' => $invoice->invoice_number,
            'description' => 'Discount on invoice '.$invoice->invoice_number.' '.($increased ? 'increased' : 'decreased').' by '.number_format($amount, 2),
            'source_module' => 'fee_billing',
            'source_type' => Invoice::class,
            'source_id' => $invoice->id,
        ], $lines, $actor);
    }

    /** Dr Fee Waivers / Cr AR for the amount forgiven, or the reverse when a waiver is removed. $amount is
     * whatever was actually outstanding at the moment of the change -- the caller (which already computes
     * this for its own business-rule checks) passes it in rather than this method re-deriving it. */
    public static function postWaiverChanged(Invoice $invoice, bool $waived, float $amount, User $actor, ?string $entryDate = null): ?JournalEntry
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            return null;
        }

        $student = $invoice->student;
        $school = $student->school;
        if (! $school->default_ar_account_id || ! $school->default_waiver_account_id) {
            return null;
        }

        $journal = self::generalJournal($school);
        if (! $journal) {
            return null;
        }

        $lines = [
            ['account_id' => $waived ? $school->default_waiver_account_id : $school->default_ar_account_id, 'debit' => $amount, 'credit' => 0],
            ['account_id' => $waived ? $school->default_ar_account_id : $school->default_waiver_account_id, 'debit' => 0, 'credit' => $amount],
        ];

        return Ledger::createAndPost($school, [
            'branch_id' => $student->branch_id,
            'journal_id' => $journal->id,
            'entry_date' => $entryDate ?? now()->toDateString(),
            'reference' => $invoice->invoice_number,
            'description' => 'Invoice '.$invoice->invoice_number.' '.($waived ? 'waived' : 'waiver removed'),
            'source_module' => 'fee_billing',
            'source_type' => Invoice::class,
            'source_id' => $invoice->id,
        ], $lines, $actor);
    }

    /** Dr Cash/Bank / Cr Accounts Receivable for a payment the moment it counts as collected (verified) --
     * never for a merely-pending portal submission, matching Finance's own "only verified counts" rule
     * exactly. The cash/bank account is the payment's own `gl_cash_account_id` if staff picked one, else the
     * school's "Undeposited Funds" clearing account. */
    public static function postPaymentVerified(Payment $payment, User $actor, ?string $entryDate = null): ?JournalEntry
    {
        $invoice = $payment->invoice;
        $student = $invoice->student;
        $school = $student->school;
        if (! $school->default_ar_account_id) {
            return null;
        }

        $cashAccountId = $payment->gl_cash_account_id ?? $school->default_cash_account_id;
        if (! $cashAccountId) {
            return null;
        }

        $journal = self::cashOrBankJournal($school, $payment->method);
        if (! $journal) {
            return null;
        }

        $amount = round((float) $payment->amount, 2);
        if ($amount <= 0) {
            return null;
        }

        return Ledger::createAndPost($school, [
            'branch_id' => $student->branch_id,
            'journal_id' => $journal->id,
            'entry_date' => $entryDate ?? now()->toDateString(),
            'reference' => $payment->receipt_number,
            'description' => "Payment received for invoice {$invoice->invoice_number}",
            'source_module' => 'payments',
            'source_type' => Payment::class,
            'source_id' => $payment->id,
        ], [
            ['account_id' => $cashAccountId, 'debit' => $amount, 'credit' => 0],
            ['account_id' => $school->default_ar_account_id, 'debit' => 0, 'credit' => $amount],
        ], $actor);
    }

    /** Dr Accounts Receivable / Cr Cash-Bank for a refund or reversal -- both kinds have the identical
     * accounting effect (money that had counted as collected no longer does), exactly mirroring how
     * Finance::cents() treats them identically when computing what's still collected. */
    public static function postPaymentRefunded(PaymentRefund $refund, User $actor, ?string $entryDate = null): ?JournalEntry
    {
        $payment = $refund->payment;
        $invoice = $payment->invoice;
        $student = $invoice->student;
        $school = $student->school;
        if (! $school->default_ar_account_id) {
            return null;
        }

        $cashAccountId = $payment->gl_cash_account_id ?? $school->default_cash_account_id;
        if (! $cashAccountId) {
            return null;
        }

        $journal = self::cashOrBankJournal($school, $payment->method);
        if (! $journal) {
            return null;
        }

        $amount = round((float) $refund->amount, 2);
        if ($amount <= 0) {
            return null;
        }

        return Ledger::createAndPost($school, [
            'branch_id' => $student->branch_id,
            'journal_id' => $journal->id,
            'entry_date' => $entryDate ?? now()->toDateString(),
            'reference' => $payment->receipt_number,
            'description' => ucfirst($refund->kind)." of payment for invoice {$invoice->invoice_number}",
            'source_module' => 'payments',
            'source_type' => PaymentRefund::class,
            'source_id' => $refund->id,
        ], [
            ['account_id' => $school->default_ar_account_id, 'debit' => $amount, 'credit' => 0],
            ['account_id' => $cashAccountId, 'debit' => 0, 'credit' => $amount],
        ], $actor);
    }

    /** Dr Expense / Cr Accounts Payable -- one debit line per distinct expense account across the bill's
     * own lines (a line's own `expense_account_id`, falling back to the school's `default_expense_account_id`
     * when unmapped). Matches the brief's own worked example exactly. */
    public static function postSupplierBillRecorded(SupplierBill $bill, User $actor, ?string $entryDate = null): ?JournalEntry
    {
        $school = $bill->school;
        if (! $school->default_ap_account_id) {
            return null;
        }

        $byAccount = [];
        foreach ($bill->items as $item) {
            $accountId = $item->expense_account_id ?? $school->default_expense_account_id;
            if (! $accountId) {
                continue;
            }
            $byAccount[$accountId] = ($byAccount[$accountId] ?? 0) + (float) $item->total;
        }
        if ($byAccount === []) {
            return null;
        }

        $journal = Journal::where('school_id', $school->id)->where('code', 'PURCH')->where('is_active', true)->first() ?? self::generalJournal($school);
        if (! $journal) {
            return null;
        }

        $total = round(array_sum($byAccount), 2);
        if ($total <= 0) {
            return null;
        }

        $lines = [];
        foreach ($byAccount as $accountId => $amount) {
            $lines[] = ['account_id' => $accountId, 'debit' => round($amount, 2), 'credit' => 0];
        }
        $lines[] = ['account_id' => $school->default_ap_account_id, 'debit' => 0, 'credit' => $total];

        return Ledger::createAndPost($school, [
            'branch_id' => $bill->branch_id,
            'journal_id' => $journal->id,
            'entry_date' => $entryDate ?? now()->toDateString(),
            'reference' => $bill->bill_number,
            'description' => "Supplier bill {$bill->bill_number} from {$bill->supplier->name}",
            'source_module' => 'procurement',
            'source_type' => SupplierBill::class,
            'source_id' => $bill->id,
        ], $lines, $actor);
    }

    /** Dr Accounts Payable / Cr Cash-Bank -- the AP mirror of postPaymentVerified(). */
    public static function postSupplierPaymentRecorded(SupplierPayment $payment, User $actor, ?string $entryDate = null): ?JournalEntry
    {
        $school = $payment->school;
        if (! $school->default_ap_account_id) {
            return null;
        }

        $cashAccountId = $payment->gl_cash_account_id ?? $school->default_cash_account_id;
        if (! $cashAccountId) {
            return null;
        }

        $journal = self::cashOrBankJournal($school, $payment->method);
        if (! $journal) {
            return null;
        }

        $amount = round((float) $payment->amount, 2);
        if ($amount <= 0) {
            return null;
        }

        return Ledger::createAndPost($school, [
            'branch_id' => $payment->branch_id,
            'journal_id' => $journal->id,
            'entry_date' => $entryDate ?? now()->toDateString(),
            'reference' => $payment->supplierBill->bill_number,
            'description' => "Payment to supplier for bill {$payment->supplierBill->bill_number}",
            'source_module' => 'procurement',
            'source_type' => SupplierPayment::class,
            'source_id' => $payment->id,
        ], [
            ['account_id' => $school->default_ap_account_id, 'debit' => $amount, 'credit' => 0],
            ['account_id' => $cashAccountId, 'debit' => 0, 'credit' => $amount],
        ], $actor);
    }

    /** Dr Cash-Bank / Cr Accounts Payable -- the exact reverse of postSupplierPaymentRecorded(), the AP
     * mirror of postPaymentRefunded(). Both kinds (refund/reversal) have the identical accounting effect:
     * money that had counted as paid no longer does, so the bill's AP balance goes back up either way. */
    public static function postSupplierPaymentReversed(SupplierPaymentReversal $reversal, User $actor, ?string $entryDate = null): ?JournalEntry
    {
        $payment = $reversal->supplierPayment;
        $school = $payment->school;
        if (! $school->default_ap_account_id) {
            return null;
        }

        $cashAccountId = $payment->gl_cash_account_id ?? $school->default_cash_account_id;
        if (! $cashAccountId) {
            return null;
        }

        $journal = self::cashOrBankJournal($school, $payment->method);
        if (! $journal) {
            return null;
        }

        $amount = round((float) $reversal->amount, 2);
        if ($amount <= 0) {
            return null;
        }

        return Ledger::createAndPost($school, [
            'branch_id' => $payment->branch_id,
            'journal_id' => $journal->id,
            'entry_date' => $entryDate ?? now()->toDateString(),
            'reference' => $payment->supplierBill->bill_number,
            'description' => ucfirst($reversal->kind)." of payment to supplier for bill {$payment->supplierBill->bill_number}",
            'source_module' => 'procurement',
            'source_type' => SupplierPaymentReversal::class,
            'source_id' => $reversal->id,
        ], [
            ['account_id' => $cashAccountId, 'debit' => $amount, 'credit' => 0],
            ['account_id' => $school->default_ap_account_id, 'debit' => 0, 'credit' => $amount],
        ], $actor);
    }

    /** Dr Salary Expense (gross) + Dr Pension Expense (employer's share) / Cr Income Tax Payable + Cr
     * Pension Payable (employee-withheld AND employer-contributed, both owed to the same pension fund) +
     * Cr Net Salary Payable -- one entry for the WHOLE run, aggregated across every payslip in it, the same
     * "one entry per document, not per line" shape every other posting in this file already uses. */
    public static function postPayrollRunFinalized(PayrollRun $run, User $actor, ?string $entryDate = null): ?JournalEntry
    {
        $school = $run->school;
        if (! $school->default_salary_expense_account_id || ! $school->default_tax_payable_account_id
            || ! $school->default_pension_payable_account_id || ! $school->default_salary_payable_account_id) {
            return null;
        }

        $payslips = $run->payslips;
        if ($payslips->isEmpty()) {
            return null;
        }

        $gross = round((float) $payslips->sum('gross_pay'), 2);
        $tax = round((float) $payslips->sum('income_tax'), 2);
        $employeePension = round((float) $payslips->sum('employee_pension'), 2);
        $employerPension = round((float) $payslips->sum('employer_pension'), 2);
        $net = round((float) $payslips->sum('net_pay'), 2);
        if ($gross <= 0) {
            return null;
        }

        $journal = Journal::where('school_id', $school->id)->where('code', 'PAYROLL')->where('is_active', true)->first() ?? self::generalJournal($school);
        if (! $journal) {
            return null;
        }

        $lines = [
            ['account_id' => $school->default_salary_expense_account_id, 'debit' => $gross, 'credit' => 0],
        ];
        if ($employerPension > 0 && $school->default_pension_expense_account_id) {
            $lines[] = ['account_id' => $school->default_pension_expense_account_id, 'debit' => $employerPension, 'credit' => 0];
        }
        if ($tax > 0) {
            $lines[] = ['account_id' => $school->default_tax_payable_account_id, 'debit' => 0, 'credit' => $tax];
        }
        $pensionPayable = round($employeePension + $employerPension, 2);
        if ($pensionPayable > 0) {
            $lines[] = ['account_id' => $school->default_pension_payable_account_id, 'debit' => 0, 'credit' => $pensionPayable];
        }
        $lines[] = ['account_id' => $school->default_salary_payable_account_id, 'debit' => 0, 'credit' => $net];

        return Ledger::createAndPost($school, [
            'branch_id' => $run->branch_id,
            'journal_id' => $journal->id,
            'entry_date' => $entryDate ?? now()->toDateString(),
            'reference' => $run->label(),
            'description' => "Payroll for {$run->branch->name} -- {$run->label()}",
            'source_module' => 'hr',
            'source_type' => PayrollRun::class,
            'source_id' => $run->id,
        ], $lines, $actor);
    }
}
