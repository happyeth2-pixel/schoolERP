<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\FeeStructure;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\SupplierBillItem;
use App\Support\ChartOfAccountsTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

/** Phase D: `accounting:backfill-ledger` catches up records that moved money/goods BEFORE a school ever
 * installed its chart of accounts -- the fee/procurement workflow itself never knows or cares whether
 * accounting exists, exactly like Phase B/C. These tests build real invoices/payments/bills the ordinary
 * way, with NO chart of accounts installed yet (so nothing posts, same as any school before adopting this
 * module), install the chart afterward as if the school just turned accounting on, then run the backfill
 * and check the result reconciles -- the same check FinancialReportController::reconciliation() performs. */
class LedgerBackfillTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchool();
    }

    private function account(string $code): Account
    {
        return Account::where('school_id', $this->school->id)->where('code', $code)->firstOrFail();
    }

    private function installAccounting(): void
    {
        ChartOfAccountsTemplate::install($this->school);
        AccountingPeriod::create(['school_id' => $this->school->id, 'name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open']);
    }

    public function test_backfill_posts_a_pre_existing_invoice_and_payment_with_their_own_historical_dates_and_reconciles(): void
    {
        $accountant = $this->makeUser('accountant', $this->branchA->id, ['manage-invoices', 'verify-payments']);
        FeeStructure::create(['branch_id' => $this->branchA->id, 'grade_id' => $this->grade->id, 'academic_year_id' => $this->year->id, 'name' => 'Tuition', 'amount' => 10000, 'frequency' => 'annual']);

        // No chart of accounts installed yet -- the invoice and payment are created exactly as any school
        // would before adopting accounting, and post nothing (the Phase B/C rule).
        $this->actingAs($accountant)->post('/invoices/generate', ['branch_id' => $this->branchA->id, 'grade_id' => $this->grade->id])->assertSessionHasNoErrors();
        $invoice = Invoice::firstOrFail();
        $this->assertCount(0, $invoice->journalEntries);

        $this->actingAs($accountant)->post("/invoices/{$invoice->id}/record-payment", ['amount' => 4000, 'method' => 'cash', 'paid_by_name' => 'A Parent'])->assertSessionHasNoErrors();
        $payment = Payment::firstOrFail();
        $this->assertCount(0, $payment->journalEntries);

        // Backdate both records to simulate they happened months ago, before accounting even existed.
        DB::table('invoices')->where('id', $invoice->id)->update(['period_start' => '2026-01-15']);
        DB::table('payments')->where('id', $payment->id)->update(['created_at' => '2026-01-20 10:00:00', 'verified_at' => '2026-01-20 10:00:00']);

        // The school now adopts accounting, same as any live school would.
        $this->installAccounting();

        Artisan::call('accounting:backfill-ledger', ['--school' => $this->school->id]);

        $invoice->refresh();
        $payment->refresh();
        $this->assertCount(1, $invoice->journalEntries);
        $this->assertSame('2026-01-15', $invoice->journalEntries->first()->entry_date->toDateString());
        $this->assertCount(1, $payment->journalEntries);
        $this->assertSame('2026-01-20', $payment->journalEntries->first()->entry_date->toDateString());

        $this->assertSame(6000.0, $this->account('1300')->balance(), 'AR = 10,000 billed minus 4,000 collected');
        $this->assertSame(10000.0, $this->account('4100')->balance());

        $viewer = $this->makeUser('accountant', null, ['accounting-view']);
        $recon = $this->props($this->actingAs($viewer)->get('/accounting/reports/reconciliation'));
        $this->assertTrue($recon['ar']['matches'], 'the ledger AR balance must equal the fee system\'s own outstanding total');
        $this->assertSame(6000.0, $recon['ar']['ledger']);
        $this->assertSame(6000.0, $recon['ar']['system']);
    }

    public function test_backfill_is_idempotent_and_never_posts_the_same_record_twice(): void
    {
        $accountant = $this->makeUser('accountant', $this->branchA->id, ['manage-invoices']);
        FeeStructure::create(['branch_id' => $this->branchA->id, 'grade_id' => $this->grade->id, 'academic_year_id' => $this->year->id, 'name' => 'Tuition', 'amount' => 5000, 'frequency' => 'annual']);
        $this->actingAs($accountant)->post('/invoices/generate', ['branch_id' => $this->branchA->id, 'grade_id' => $this->grade->id]);
        $invoice = Invoice::firstOrFail();
        DB::table('invoices')->where('id', $invoice->id)->update(['period_start' => '2026-02-01']);

        $this->installAccounting();

        Artisan::call('accounting:backfill-ledger', ['--school' => $this->school->id]);
        $this->assertSame(1, JournalEntry::count());

        Artisan::call('accounting:backfill-ledger', ['--school' => $this->school->id]);
        $this->assertSame(1, JournalEntry::count(), 're-running the backfill must not create a second entry for an already-posted invoice');
    }

    public function test_backfill_skips_a_date_already_covered_by_a_closed_period_and_reports_it(): void
    {
        $accountant = $this->makeUser('accountant', $this->branchA->id, ['manage-invoices']);
        FeeStructure::create(['branch_id' => $this->branchA->id, 'grade_id' => $this->grade->id, 'academic_year_id' => $this->year->id, 'name' => 'Tuition', 'amount' => 3000, 'frequency' => 'annual']);
        $this->actingAs($accountant)->post('/invoices/generate', ['branch_id' => $this->branchA->id, 'grade_id' => $this->grade->id]);
        $invoice = Invoice::firstOrFail();
        DB::table('invoices')->where('id', $invoice->id)->update(['period_start' => '2025-06-01']);

        ChartOfAccountsTemplate::install($this->school);
        AccountingPeriod::create(['school_id' => $this->school->id, 'name' => 'Already closed', 'start_date' => '2025-01-01', 'end_date' => '2025-12-31', 'status' => 'closed']);

        Artisan::call('accounting:backfill-ledger', ['--school' => $this->school->id]);

        $invoice->refresh();
        $this->assertCount(0, $invoice->journalEntries, 'a date already covered by a closed period must never be force-posted');
    }

    public function test_dry_run_posts_nothing_and_creates_no_periods(): void
    {
        $accountant = $this->makeUser('accountant', $this->branchA->id, ['manage-invoices']);
        FeeStructure::create(['branch_id' => $this->branchA->id, 'grade_id' => $this->grade->id, 'academic_year_id' => $this->year->id, 'name' => 'Tuition', 'amount' => 2000, 'frequency' => 'annual']);
        $this->actingAs($accountant)->post('/invoices/generate', ['branch_id' => $this->branchA->id, 'grade_id' => $this->grade->id]);
        $invoice = Invoice::firstOrFail();
        DB::table('invoices')->where('id', $invoice->id)->update(['period_start' => '2026-03-01']);

        $this->installAccounting();
        $periodsBefore = AccountingPeriod::count();

        Artisan::call('accounting:backfill-ledger', ['--school' => $this->school->id, '--dry-run' => true]);

        $invoice->refresh();
        $this->assertCount(0, $invoice->journalEntries);
        $this->assertSame($periodsBefore, AccountingPeriod::count());
    }

    public function test_backfill_posts_a_pre_existing_supplier_bill_with_its_own_historical_date(): void
    {
        $this->installAccounting();
        $this->owner->syncPermissions(['supplier-bill-manage']);
        $supplier = Supplier::create(['school_id' => $this->school->id, 'code' => 'SUP-1', 'name' => 'ABC Supplies']);
        $bill = SupplierBill::create(['school_id' => $this->school->id, 'branch_id' => $this->branchA->id, 'supplier_id' => $supplier->id, 'bill_number' => 'BILL-OLD-1', 'bill_date' => '2026-01-05', 'created_by' => $this->owner->id]);
        SupplierBillItem::create(['supplier_bill_id' => $bill->id, 'description' => 'Chairs', 'quantity' => 1, 'unit_price' => 1500, 'tax_amount' => 0, 'total' => 1500]);
        $this->assertCount(0, $bill->journalEntries);

        Artisan::call('accounting:backfill-ledger', ['--school' => $this->school->id]);

        $bill->refresh();
        $this->assertCount(1, $bill->journalEntries);
        $this->assertSame('2026-01-05', $bill->journalEntries->first()->entry_date->toDateString());
        $this->assertSame(1500.0, $this->account('2100')->balance());
    }
}
