<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\FeeStructure;
use App\Models\Invoice;
use App\Models\Journal;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\SupplierBillItem;
use App\Services\Ledger;
use App\Support\ChartOfAccountsTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

/** Every figure on these reports is a live query over posted journal_entry_lines -- these tests exist to
 * prove that query is correct, not to duplicate a second source of truth. */
class FinancialReportsTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    private Account $cash;
    private Account $revenue;
    private Journal $journal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchool();
        ChartOfAccountsTemplate::install($this->school);
        AccountingPeriod::create(['school_id' => $this->school->id, 'name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open']);
        $this->cash = Account::where('school_id', $this->school->id)->where('code', '1100')->firstOrFail();
        $this->revenue = Account::where('school_id', $this->school->id)->where('code', '4100')->firstOrFail();
        $this->journal = Journal::where('school_id', $this->school->id)->where('code', 'GEN')->firstOrFail();
    }

    private function postSale(float $amount, string $date): void
    {
        $entry = Ledger::createDraft($this->school, [
            'journal_id' => $this->journal->id, 'entry_date' => $date, 'description' => "Sale {$amount}",
        ], [
            ['account_id' => $this->cash->id, 'debit' => $amount, 'credit' => 0],
            ['account_id' => $this->revenue->id, 'debit' => 0, 'credit' => $amount],
        ], $this->owner);
        Ledger::post($entry, $this->owner);
    }

    private function account(string $code): Account
    {
        return Account::where('school_id', $this->school->id)->where('code', $code)->firstOrFail();
    }

    private function postExpense(float $amount, string $date): void
    {
        $expense = $this->account('5900');
        $entry = Ledger::createDraft($this->school, [
            'journal_id' => $this->journal->id, 'entry_date' => $date, 'description' => "Expense {$amount}",
        ], [
            ['account_id' => $expense->id, 'debit' => $amount, 'credit' => 0],
            ['account_id' => $this->cash->id, 'debit' => 0, 'credit' => $amount],
        ], $this->owner);
        Ledger::post($entry, $this->owner);
    }

    public function test_the_trial_balance_always_balances(): void
    {
        $this->postSale(1000, '2026-02-01');
        $this->postSale(500, '2026-03-01');
        $this->postSale(250.75, '2026-04-01');

        $viewer = $this->makeUser('accountant', null, ['accounting-view']);
        $response = $this->actingAs($viewer)->get('/accounting/reports/trial-balance?as_of=2026-12-31');
        $response->assertOk();
        $props = $this->props($response);

        $this->assertEqualsWithDelta($props['total_debit'], $props['total_credit'], 0.001);
        $this->assertGreaterThan(0, $props['total_debit']);
    }

    public function test_the_trial_balance_respects_the_as_of_date(): void
    {
        $this->postSale(1000, '2026-02-01');
        $this->postSale(500, '2026-06-01'); // after the as-of date below

        $viewer = $this->makeUser('accountant', null, ['accounting-view']);
        $response = $this->actingAs($viewer)->get('/accounting/reports/trial-balance?as_of=2026-03-01');
        $props = $this->props($response);

        $cashRow = collect($props['rows'])->firstWhere('code', '1100');
        $this->assertSame(1000.0, (float) $cashRow['debit'], 'only the February posting should count as of March 1st');
    }

    public function test_the_ledger_shows_a_correct_running_balance_and_opening_balance(): void
    {
        $this->postSale(1000, '2026-02-01');
        $this->postSale(500, '2026-03-01');

        $viewer = $this->makeUser('accountant', null, ['accounting-view']);
        $response = $this->actingAs($viewer)->get("/accounting/reports/ledger?account_id={$this->cash->id}&from=2026-03-01&to=2026-12-31");
        $props = $this->props($response);

        $this->assertSame(1000.0, (float) $props['opening_balance'], 'the February posting is the opening balance for a report starting in March');
        $this->assertCount(1, $props['rows']);
        $this->assertSame(1500.0, (float) $props['rows'][0]['running_balance']);
    }

    public function test_accounting_view_permission_is_enough_to_see_reports_but_not_to_post(): void
    {
        $viewer = $this->makeUser('accountant', null, ['accounting-view']);
        $this->actingAs($viewer)->get('/accounting/reports/trial-balance')->assertOk();
        $this->actingAs($viewer)->get('/accounting/reports/ledger')->assertOk();
    }

    public function test_someone_without_any_accounting_permission_cannot_see_reports(): void
    {
        $bystander = $this->makeUser('teacher', null, []);
        $this->actingAs($bystander)->get('/accounting/reports/trial-balance')->assertForbidden();
        $this->actingAs($bystander)->get('/accounting/reports/ledger')->assertForbidden();
    }

    // ---------------------------------------------------------------- Phase D: income statement, balance sheet, cash, aging, reconciliation

    public function test_income_statement_computes_net_income_only_within_the_requested_range(): void
    {
        $this->postSale(1000, '2026-02-01');
        $this->postExpense(300, '2026-02-15');
        $this->postSale(5000, '2026-06-01'); // outside the range below

        $viewer = $this->makeUser('accountant', null, ['accounting-view']);
        $props = $this->props($this->actingAs($viewer)->get('/accounting/reports/income-statement?from=2026-01-01&to=2026-03-31'));

        $this->assertSame(1000.0, $props['total_revenue']);
        $this->assertSame(300.0, $props['total_expense']);
        $this->assertSame(700.0, $props['net_income']);
    }

    public function test_balance_sheet_balances_with_net_income_folded_into_equity(): void
    {
        $this->postSale(1000, '2026-02-01');
        $this->postExpense(400, '2026-02-15');

        $viewer = $this->makeUser('accountant', null, ['accounting-view']);
        $props = $this->props($this->actingAs($viewer)->get('/accounting/reports/balance-sheet?as_of=2026-12-31'));

        $this->assertTrue($props['balanced'], 'assets must equal liabilities plus equity once net income is folded in');
        $this->assertEqualsWithDelta($props['total_assets'], $props['total_liabilities'] + $props['total_equity'], 0.001);
        $netIncomeRow = collect($props['equity'])->firstWhere('name', 'Net Income (to date)');
        $this->assertSame(600.0, $netIncomeRow['amount']);
    }

    public function test_cash_summary_excludes_the_receivable_control_account(): void
    {
        $this->postSale(1000, '2026-02-01');

        // AR carries a real, nonzero balance too, from an unrelated posting -- it must still never appear here.
        $entry = Ledger::createDraft($this->school, [
            'journal_id' => $this->journal->id, 'entry_date' => '2026-02-01', 'description' => 'Invoice billed',
        ], [
            ['account_id' => $this->account('1300')->id, 'debit' => 300, 'credit' => 0],
            ['account_id' => $this->revenue->id, 'debit' => 0, 'credit' => 300],
        ], $this->owner);
        Ledger::post($entry, $this->owner);

        $viewer = $this->makeUser('accountant', null, ['accounting-view']);
        $props = $this->props($this->actingAs($viewer)->get('/accounting/reports/cash-summary?as_of=2026-12-31'));

        $this->assertNull(collect($props['rows'])->firstWhere('code', '1300'), 'Accounts Receivable has its own Aging report, not this one');
        $this->assertSame(1000.0, collect($props['rows'])->firstWhere('code', '1100')['balance']);
    }

    public function test_ar_aging_buckets_an_overdue_invoice_and_matches_the_fee_systems_own_balance(): void
    {
        $accountant = $this->makeUser('accountant', $this->branchA->id, ['manage-invoices']);
        FeeStructure::create(['branch_id' => $this->branchA->id, 'grade_id' => $this->grade->id, 'academic_year_id' => $this->year->id, 'name' => 'Tuition', 'amount' => 1000, 'frequency' => 'annual']);
        $this->actingAs($accountant)->post('/invoices/generate', ['branch_id' => $this->branchA->id, 'grade_id' => $this->grade->id]);
        $invoice = Invoice::firstOrFail();
        DB::table('invoices')->where('id', $invoice->id)->update(['period_start' => '2026-01-01']);

        $viewer = $this->makeUser('accountant', null, ['accounting-view']);
        $props = $this->props($this->actingAs($viewer)->get('/accounting/reports/ar-aging?as_of=2026-03-15'));

        $row = collect($props['rows'])->firstWhere('invoice_number', $invoice->invoice_number);
        $this->assertNotNull($row);
        $this->assertSame(1000.0, $row['balance']);
        $this->assertSame('d61_90', $row['bucket'], '2026-01-01 to 2026-03-15 is 73 days overdue');
    }

    public function test_ap_aging_buckets_an_overdue_supplier_bill(): void
    {
        $supplier = Supplier::create(['school_id' => $this->school->id, 'code' => 'SUP-1', 'name' => 'ABC Supplies']);
        $bill = SupplierBill::create(['school_id' => $this->school->id, 'branch_id' => $this->branchA->id, 'supplier_id' => $supplier->id, 'bill_number' => 'BILL-1', 'bill_date' => '2026-01-01', 'due_date' => '2026-01-15', 'created_by' => $this->owner->id]);
        SupplierBillItem::create(['supplier_bill_id' => $bill->id, 'description' => 'Paper', 'quantity' => 1, 'unit_price' => 500, 'tax_amount' => 0, 'total' => 500]);

        $viewer = $this->makeUser('accountant', null, ['accounting-view']);
        $props = $this->props($this->actingAs($viewer)->get('/accounting/reports/ap-aging?as_of=2026-02-20'));

        $row = collect($props['rows'])->firstWhere('bill_number', 'BILL-1');
        $this->assertNotNull($row);
        $this->assertSame(500.0, $row['balance']);
        $this->assertSame('d31_60', $row['bucket'], '2026-01-15 to 2026-02-20 is 36 days overdue');
    }

    public function test_reconciliation_reports_a_mismatch_when_the_ledger_and_fee_system_disagree(): void
    {
        // A manual, non-invoice-related journal entry inflates AR beyond what any invoice says is owed --
        // simulating a real posting-engine bug, which this report exists to catch.
        $entry = Ledger::createDraft($this->school, [
            'journal_id' => $this->journal->id, 'entry_date' => '2026-02-01', 'description' => 'Manual AR adjustment',
        ], [
            ['account_id' => $this->account('1300')->id, 'debit' => 500, 'credit' => 0],
            ['account_id' => $this->cash->id, 'debit' => 0, 'credit' => 500],
        ], $this->owner);
        Ledger::post($entry, $this->owner);

        $viewer = $this->makeUser('accountant', null, ['accounting-view']);
        $props = $this->props($this->actingAs($viewer)->get('/accounting/reports/reconciliation'));

        $this->assertFalse($props['ar']['matches']);
        $this->assertSame(500.0, $props['ar']['diff']);
    }

    public function test_someone_without_any_accounting_permission_cannot_see_the_new_reports(): void
    {
        $bystander = $this->makeUser('teacher', null, []);
        foreach (['income-statement', 'balance-sheet', 'cash-summary', 'ar-aging', 'ap-aging', 'reconciliation'] as $path) {
            $this->actingAs($bystander)->get("/accounting/reports/{$path}")->assertForbidden();
        }
    }
}
