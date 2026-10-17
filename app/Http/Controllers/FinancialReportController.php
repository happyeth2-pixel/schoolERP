<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Invoice;
use App\Models\JournalEntryLine;
use App\Models\SupplierBill;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Every figure here is a live query over posted journal_entry_lines -- never a separately duplicated
 * total. If it doesn't add up, that's a real bug in the posting engine, not a reporting bug -- there is
 * nowhere else the numbers could have come from. */
class FinancialReportController extends Controller
{
    private function canView(User $user): bool
    {
        return $user->can('accounting-view') || $user->can('accounting-manage') || $user->hasRole('auditor');
    }

    public function ledger(Request $request): Response
    {
        $user = $request->user();
        abort_unless($this->canView($user), 403);

        $accounts = Account::where('school_id', $user->school_id)->where('is_header', false)->orderBy('code')->get(['id', 'code', 'name']);
        $accountId = $request->integer('account_id') ?: $accounts->first()?->id;
        $from = $request->query('from');
        $to = $request->query('to');

        $rows = [];
        $account = $accountId ? Account::find($accountId) : null;
        if ($account && $account->school_id === $user->school_id) {
            $lines = JournalEntryLine::where('account_id', $account->id)
                ->whereHas('journalEntry', fn ($q) => $q->where('status', 'posted')
                    ->when($from, fn ($q2) => $q2->where('entry_date', '>=', $from))
                    ->when($to, fn ($q2) => $q2->where('entry_date', '<=', $to)))
                ->with('journalEntry:id,entry_number,entry_date,description')
                ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
                ->orderBy('journal_entries.entry_date')
                ->orderBy('journal_entry_lines.id')
                ->select('journal_entry_lines.*')
                ->get();

            $openingDebit = (float) JournalEntryLine::where('account_id', $account->id)
                ->whereHas('journalEntry', fn ($q) => $q->where('status', 'posted')->when($from, fn ($q2) => $q2->where('entry_date', '<', $from)))
                ->sum('debit');
            $openingCredit = (float) JournalEntryLine::where('account_id', $account->id)
                ->whereHas('journalEntry', fn ($q) => $q->where('status', 'posted')->when($from, fn ($q2) => $q2->where('entry_date', '<', $from)))
                ->sum('credit');
            $running = $account->normal_balance === 'debit' ? $openingDebit - $openingCredit : $openingCredit - $openingDebit;
            $opening = $running;

            foreach ($lines as $line) {
                $signed = $account->normal_balance === 'debit' ? ((float) $line->debit - (float) $line->credit) : ((float) $line->credit - (float) $line->debit);
                $running += $signed;
                $rows[] = [
                    'entry_number' => $line->journalEntry->entry_number,
                    'entry_date' => $line->journalEntry->entry_date->toDateString(),
                    'description' => $line->description ?: $line->journalEntry->description,
                    'debit' => (float) $line->debit,
                    'credit' => (float) $line->credit,
                    'running_balance' => $running,
                ];
            }
        }

        return Inertia::render('Accounting/Reports/Ledger', [
            'accounts' => $accounts,
            'account_id' => $account?->id,
            'from' => $from,
            'to' => $to,
            'opening_balance' => $opening ?? 0,
            'rows' => $rows,
        ]);
    }

    public function trialBalance(Request $request): Response
    {
        $user = $request->user();
        abort_unless($this->canView($user), 403);

        $asOf = $request->query('as_of', now()->toDateString());

        $accounts = Account::where('school_id', $user->school_id)->where('is_header', false)->where('is_active', true)->orderBy('code')->get();

        $rows = $accounts->map(function (Account $account) use ($asOf) {
            $balance = $account->balance($asOf);
            $onNormalSide = $balance >= 0;
            [$debit, $credit] = $account->normal_balance === 'debit'
                ? ($onNormalSide ? [$balance, 0] : [0, -$balance])
                : ($onNormalSide ? [0, $balance] : [-$balance, 0]);

            return ['code' => $account->code, 'name' => $account->name, 'type' => $account->type, 'debit' => $debit, 'credit' => $credit];
        })->filter(fn ($r) => $r['debit'] != 0 || $r['credit'] != 0)->values();

        return Inertia::render('Accounting/Reports/TrialBalance', [
            'as_of' => $asOf,
            'rows' => $rows,
            'total_debit' => $rows->sum('debit'),
            'total_credit' => $rows->sum('credit'),
        ]);
    }

    /** Revenue minus expense for a date RANGE (never cumulative since inception, unlike the Balance Sheet) --
     * every revenue/expense account shown individually, including contra-revenue accounts (discounts,
     * waivers) which net straight out of the revenue total since they share the same account type. */
    public function incomeStatement(Request $request): Response
    {
        $user = $request->user();
        abort_unless($this->canView($user), 403);

        $from = $request->query('from', now()->startOfYear()->toDateString());
        $to = $request->query('to', now()->toDateString());

        $accounts = Account::where('school_id', $user->school_id)->where('is_header', false)->where('is_active', true)
            ->whereIn('type', ['revenue', 'expense'])->orderBy('code')->get();

        $revenue = [];
        $expense = [];
        foreach ($accounts as $account) {
            $amount = $account->movementBetween($from, $to);
            if (abs($amount) < 0.005) {
                continue;
            }
            $row = ['code' => $account->code, 'name' => $account->name, 'amount' => $amount];
            if ($account->type === 'revenue') {
                $revenue[] = $row;
            } else {
                $expense[] = $row;
            }
        }

        $totalRevenue = array_sum(array_column($revenue, 'amount'));
        $totalExpense = array_sum(array_column($expense, 'amount'));

        return Inertia::render('Accounting/Reports/IncomeStatement', [
            'from' => $from,
            'to' => $to,
            'revenue' => $revenue,
            'expense' => $expense,
            'total_revenue' => $totalRevenue,
            'total_expense' => $totalExpense,
            'net_income' => $totalRevenue - $totalExpense,
        ]);
    }

    /** As-of-date balances for Asset/Liability/Equity accounts. Since nothing here ever posts a
     * period-closing entry, net income earned to date is folded into Equity as its own line (computed live,
     * same as everything else) so Assets always equals Liabilities + Equity. */
    public function balanceSheet(Request $request): Response
    {
        $user = $request->user();
        abort_unless($this->canView($user), 403);

        $asOf = $request->query('as_of', now()->toDateString());

        $accounts = Account::where('school_id', $user->school_id)->where('is_header', false)->where('is_active', true)
            ->whereIn('type', ['asset', 'liability', 'equity'])->orderBy('code')->get();

        $assets = [];
        $liabilities = [];
        $equity = [];
        foreach ($accounts as $account) {
            $balance = $account->balance($asOf);
            if (abs($balance) < 0.005) {
                continue;
            }
            $row = ['code' => $account->code, 'name' => $account->name, 'amount' => $balance];
            match ($account->type) {
                'asset' => $assets[] = $row,
                'liability' => $liabilities[] = $row,
                'equity' => $equity[] = $row,
            };
        }

        $netIncome = Account::where('school_id', $user->school_id)->where('is_header', false)
            ->whereIn('type', ['revenue', 'expense'])->get()
            ->sum(fn (Account $a) => $a->type === 'revenue' ? $a->balance($asOf) : -$a->balance($asOf));
        if (abs($netIncome) >= 0.005) {
            $equity[] = ['code' => null, 'name' => 'Net Income (to date)', 'amount' => $netIncome];
        }

        $totalAssets = array_sum(array_column($assets, 'amount'));
        $totalLiabilities = array_sum(array_column($liabilities, 'amount'));
        $totalEquity = array_sum(array_column($equity, 'amount'));

        return Inertia::render('Accounting/Reports/BalanceSheet', [
            'as_of' => $asOf,
            'assets' => $assets,
            'liabilities' => $liabilities,
            'equity' => $equity,
            'total_assets' => $totalAssets,
            'total_liabilities' => $totalLiabilities,
            'total_equity' => $totalEquity,
            'balanced' => abs($totalAssets - ($totalLiabilities + $totalEquity)) < 0.005,
        ]);
    }

    /** Every leaf asset account EXCEPT the Accounts Receivable control account (which has its own Aging
     * report) -- for the default chart that is Cash/Undeposited Funds/Bank, and any further cash-like asset
     * account a school adds of its own stays included automatically, without hardcoding a code. */
    public function cashSummary(Request $request): Response
    {
        $user = $request->user();
        abort_unless($this->canView($user), 403);
        $school = $user->school;

        $asOf = $request->query('as_of', now()->toDateString());

        $rows = Account::where('school_id', $user->school_id)->where('is_header', false)->where('is_active', true)
            ->where('type', 'asset')
            ->when($school->default_ar_account_id, fn ($q) => $q->where('id', '!=', $school->default_ar_account_id))
            ->orderBy('code')->get()
            ->map(fn (Account $a) => ['code' => $a->code, 'name' => $a->name, 'balance' => $a->balance($asOf)])
            ->filter(fn ($r) => abs($r['balance']) >= 0.005)->values();

        return Inertia::render('Accounting/Reports/CashSummary', [
            'as_of' => $asOf,
            'rows' => $rows,
            'total' => $rows->sum('balance'),
        ]);
    }

    private function agingBucket(int $daysOverdue): string
    {
        return match (true) {
            $daysOverdue <= 0 => 'current',
            $daysOverdue <= 30 => 'd1_30',
            $daysOverdue <= 60 => 'd31_60',
            $daysOverdue <= 90 => 'd61_90',
            default => 'd90_plus',
        };
    }

    /** Outstanding invoice balances bucketed by days since the invoice's own billing period started --
     * invoices have no separate due-date field, so the period start is the honest proxy for "when this was
     * due". Balances are read straight from Invoice::balance() (App\Services\Finance), never recomputed --
     * the fee system stays the one source of truth for what a family owes. */
    public function arAging(Request $request): Response
    {
        $user = $request->user();
        abort_unless($this->canView($user), 403);

        $asOf = $request->query('as_of', now()->toDateString());
        $asOfTs = strtotime($asOf);

        $rows = Invoice::whereHas('student', fn ($q) => $q->where('school_id', $user->school_id))
            ->with(['student', 'items', 'payments.refunds'])
            ->get()
            ->map(function (Invoice $invoice) use ($asOfTs) {
                $balance = $invoice->balance();
                if ($balance <= 0.005) {
                    return null;
                }
                $daysOverdue = (int) floor(($asOfTs - strtotime($invoice->period_start->toDateString())) / 86400);

                return [
                    'invoice_number' => $invoice->invoice_number,
                    'student' => $invoice->student->fullName(),
                    'period_start' => $invoice->period_start->toDateString(),
                    'balance' => $balance,
                    'days_overdue' => $daysOverdue,
                    'bucket' => $this->agingBucket($daysOverdue),
                ];
            })->filter()->values();

        $buckets = collect(['current', 'd1_30', 'd31_60', 'd61_90', 'd90_plus'])
            ->mapWithKeys(fn ($b) => [$b => $rows->where('bucket', $b)->sum('balance')]);

        return Inertia::render('Accounting/Reports/ArAging', [
            'as_of' => $asOf,
            'rows' => $rows,
            'buckets' => $buckets,
            'total' => $rows->sum('balance'),
        ]);
    }

    /** The AP mirror of arAging() -- bucketed from the bill's own due_date (or its bill_date when no due
     * date was given), over SupplierBill::balance(). */
    public function apAging(Request $request): Response
    {
        $user = $request->user();
        abort_unless($this->canView($user), 403);

        $asOf = $request->query('as_of', now()->toDateString());
        $asOfTs = strtotime($asOf);

        $rows = SupplierBill::where('school_id', $user->school_id)
            ->with(['supplier', 'items', 'payments'])
            ->get()
            ->map(function (SupplierBill $bill) use ($asOfTs) {
                $balance = $bill->balance();
                if ($balance <= 0.005) {
                    return null;
                }
                $dueDate = $bill->due_date ?? $bill->bill_date;
                $daysOverdue = (int) floor(($asOfTs - strtotime($dueDate->toDateString())) / 86400);

                return [
                    'bill_number' => $bill->bill_number,
                    'supplier' => $bill->supplier->name,
                    'due_date' => $dueDate->toDateString(),
                    'balance' => $balance,
                    'days_overdue' => $daysOverdue,
                    'bucket' => $this->agingBucket($daysOverdue),
                ];
            })->filter()->values();

        $buckets = collect(['current', 'd1_30', 'd31_60', 'd61_90', 'd90_plus'])
            ->mapWithKeys(fn ($b) => [$b => $rows->where('bucket', $b)->sum('balance')]);

        return Inertia::render('Accounting/Reports/ApAging', [
            'as_of' => $asOf,
            'rows' => $rows,
            'buckets' => $buckets,
            'total' => $rows->sum('balance'),
        ]);
    }

    /** The reconciliation tool the whole engine promises: the AR/AP control account balances the ledger
     * carries MUST equal the independently-computed live totals the fee/procurement systems themselves say
     * are outstanding -- if they ever diverge, that is a real bug in the posting engine (a gap this report
     * exists to catch), never something to paper over here. Safe to re-run any time, not just after a
     * backfill. */
    public function reconciliation(Request $request): Response
    {
        $user = $request->user();
        abort_unless($this->canView($user), 403);
        $school = $user->school;

        $ar = null;
        if ($school->default_ar_account_id) {
            $ledger = $school->defaultArAccount->balance();
            $system = (float) Invoice::whereHas('student', fn ($q) => $q->where('school_id', $school->id))
                ->with(['items', 'payments.refunds'])->get()->sum(fn (Invoice $i) => $i->balance());
            $ar = ['ledger' => $ledger, 'system' => $system, 'diff' => round($ledger - $system, 2), 'matches' => abs($ledger - $system) < 0.01];
        }

        $ap = null;
        if ($school->default_ap_account_id) {
            $ledger = $school->defaultApAccount->balance();
            $system = (float) SupplierBill::where('school_id', $school->id)
                ->with(['items', 'payments'])->get()->sum(fn (SupplierBill $b) => $b->balance());
            $ap = ['ledger' => $ledger, 'system' => $system, 'diff' => round($ledger - $system, 2), 'matches' => abs($ledger - $system) < 0.01];
        }

        return Inertia::render('Accounting/Reports/Reconciliation', [
            'accounting_installed' => (bool) $school->default_ar_account_id,
            'ar' => $ar,
            'ap' => $ap,
            'checked_at' => now()->toDateTimeString(),
        ]);
    }
}
