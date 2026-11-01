<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Invoice;
use App\Models\Journal;
use App\Models\School;
use App\Services\Ledger;
use App\Support\ChartOfAccountsTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

/** The giant-button Accounting Overview is just a simpler front door onto numbers the full reports already
 * prove correct -- these tests only check it reads the right accounts and gates access the same way. */
class AccountingOverviewTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    public function test_it_reports_not_installed_when_the_chart_of_accounts_is_missing(): void
    {
        $this->buildSchool();
        $viewer = $this->makeUser('accountant', null, ['accounting-view']);

        $props = $this->props($this->actingAs($viewer)->get('/accounting/overview'));

        $this->assertFalse($props['installed']);
    }

    public function test_it_summarizes_cash_revenue_expense_and_receivables_once_installed(): void
    {
        $this->buildSchool();
        ChartOfAccountsTemplate::install($this->school);
        AccountingPeriod::create(['school_id' => $this->school->id, 'name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open']);

        $journal = Journal::where('school_id', $this->school->id)->where('code', 'GEN')->firstOrFail();
        $cash = Account::where('school_id', $this->school->id)->where('code', '1100')->firstOrFail();
        $revenue = Account::where('school_id', $this->school->id)->where('code', '4100')->firstOrFail();
        $expense = Account::where('school_id', $this->school->id)->where('code', '5900')->firstOrFail();
        $ar = Account::where('school_id', $this->school->id)->where('code', '1300')->firstOrFail();

        $sale = Ledger::createDraft($this->school, [
            'journal_id' => $journal->id, 'entry_date' => now()->toDateString(), 'description' => 'Sale',
        ], [
            ['account_id' => $cash->id, 'debit' => 1000, 'credit' => 0],
            ['account_id' => $revenue->id, 'debit' => 0, 'credit' => 1000],
        ], $this->owner);
        Ledger::post($sale, $this->owner);

        $billed = Ledger::createDraft($this->school, [
            'journal_id' => $journal->id, 'entry_date' => now()->toDateString(), 'description' => 'Billed but unpaid',
        ], [
            ['account_id' => $ar->id, 'debit' => 300, 'credit' => 0],
            ['account_id' => $revenue->id, 'debit' => 0, 'credit' => 300],
        ], $this->owner);
        Ledger::post($billed, $this->owner);

        // The manual journal entry above is the LEDGER side of this bill (so the account balance and
        // `reconciled` agree) -- `ar_total` itself is computed from real Invoice records (see
        // AccountingOverviewController), same as the "Who Still Owes" report, so it also needs one here.
        $invoice = Invoice::create(['student_id' => $this->studentA->id, 'academic_year_id' => $this->year->id, 'invoice_number' => 'INV-OV-1', 'total_amount' => 300, 'status' => 'pending', 'frequency' => 'annual', 'period_start' => now()->toDateString()]);
        $invoice->items()->create(['description' => 'Tuition', 'amount' => 300, 'item_type' => 'fee']);

        $spend = Ledger::createDraft($this->school, [
            'journal_id' => $journal->id, 'entry_date' => now()->toDateString(), 'description' => 'Expense',
        ], [
            ['account_id' => $expense->id, 'debit' => 400, 'credit' => 0],
            ['account_id' => $cash->id, 'debit' => 0, 'credit' => 400],
        ], $this->owner);
        Ledger::post($spend, $this->owner);

        $viewer = $this->makeUser('accountant', null, ['accounting-view']);
        $props = $this->props($this->actingAs($viewer)->get('/accounting/overview'));

        $this->assertTrue($props['installed']);
        $this->assertSame(600.0, $props['cash_total']);
        $this->assertSame(1300.0, $props['revenue']);
        $this->assertSame(400.0, $props['expense']);
        $this->assertSame(300.0, $props['ar_total']);
        $this->assertTrue($props['reconciled']);
    }

    public function test_someone_without_accounting_permission_is_forbidden(): void
    {
        $this->buildSchool();
        $bystander = $this->makeUser('teacher', null, []);

        $this->actingAs($bystander)->get('/accounting/overview')->assertForbidden();
    }

    public function test_it_is_scoped_to_the_viewers_own_school(): void
    {
        $this->buildSchool();
        ChartOfAccountsTemplate::install($this->school);

        $otherSchool = School::create(['name' => 'Other', 'code' => 'OTH'.Str::random(3)]);
        ChartOfAccountsTemplate::install($otherSchool);

        $viewer = $this->makeUser('accountant', null, ['accounting-view']);
        $props = $this->props($this->actingAs($viewer)->get('/accounting/overview'));

        $this->assertTrue($props['installed']);
        $this->assertSame(0.0, $props['cash_total']);
    }
}
