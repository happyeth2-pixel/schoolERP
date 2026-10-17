<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\FeeStructure;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\OptionalService;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\User;
use App\Support\ChartOfAccountsTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

/** Phase B: wiring the EXISTING fee/invoice/payment system into the posting engine. The fee system remains
 * the one source of truth for what a family owes -- these tests exist to prove the ledger always mirrors it
 * correctly (reconciliation), and, just as importantly, that the fee workflow is NEVER blocked by anything
 * accounting-related, with or without a chart of accounts installed. */
class AccountingIntegrationTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    private User $accountant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchool();
        $this->accountant = $this->makeUser('accountant', $this->branchA->id, ['manage-invoices', 'verify-payments', 'refund-payments', 'approve-large-discounts']);
    }

    private function installAccounting(): void
    {
        ChartOfAccountsTemplate::install($this->school);
        AccountingPeriod::create(['school_id' => $this->school->id, 'name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open']);
    }

    private function account(string $code): Account
    {
        return Account::where('school_id', $this->school->id)->where('code', $code)->firstOrFail();
    }

    private function fee(string $name, float $amount, string $frequency = 'annual', ?int $revenueAccountId = null): FeeStructure
    {
        return FeeStructure::create(['branch_id' => $this->branchA->id, 'grade_id' => $this->grade->id, 'academic_year_id' => $this->year->id, 'name' => $name, 'amount' => $amount, 'frequency' => $frequency, 'revenue_account_id' => $revenueAccountId]);
    }

    // ---------------------------------------------------------------- invoice issued

    public function test_generating_an_invoice_posts_ar_and_revenue_matching_the_example_in_the_brief(): void
    {
        $this->installAccounting();
        $this->fee('Tuition', 10000);

        $this->actingAs($this->accountant)->post('/invoices/generate', ['branch_id' => $this->branchA->id, 'grade_id' => $this->grade->id])->assertSessionHasNoErrors();
        $invoice = Invoice::firstOrFail();

        $entry = $invoice->journalEntries()->firstOrFail();
        $this->assertSame('posted', $entry->status);
        $this->assertSame(10000.0, $entry->totalDebit());
        $arLine = $entry->lines->firstWhere('account_id', $this->account('1300')->id);
        $revenueLine = $entry->lines->firstWhere('account_id', $this->account('4100')->id);
        $this->assertSame(10000.0, (float) $arLine->debit);
        $this->assertSame(10000.0, (float) $revenueLine->credit);
    }

    public function test_a_multi_line_invoice_credits_a_different_revenue_account_per_fee_or_service(): void
    {
        $this->installAccounting();
        $tuitionAccount = $this->account('4100');
        $otherRevenue = Account::create(['school_id' => $this->school->id, 'code' => '4300', 'name' => 'Transport Fees', 'type' => 'revenue', 'normal_balance' => 'credit']);
        $this->fee('Tuition', 8000, 'annual', $tuitionAccount->id);
        OptionalService::create(['branch_id' => $this->branchA->id, 'academic_year_id' => $this->year->id, 'name' => 'Bus', 'category' => 'transport', 'amount' => 2000, 'frequency' => 'annual', 'revenue_account_id' => $otherRevenue->id]);
        $this->studentA->optionalServices()->attach(OptionalService::first()->id, ['enrolled_on' => now()->toDateString()]);

        $this->actingAs($this->accountant)->post('/invoices/generate', ['branch_id' => $this->branchA->id, 'grade_id' => $this->grade->id]);
        $invoice = Invoice::firstOrFail();

        $entry = $invoice->journalEntries()->firstOrFail();
        $this->assertSame(10000.0, $entry->totalDebit());
        $this->assertSame(8000.0, (float) $entry->lines->firstWhere('account_id', $tuitionAccount->id)->credit);
        $this->assertSame(2000.0, (float) $entry->lines->firstWhere('account_id', $otherRevenue->id)->credit);
    }

    // ---------------------------------------------------------------- discount / waiver

    public function test_increasing_then_decreasing_a_discount_posts_only_the_delta_each_time(): void
    {
        $this->installAccounting();
        $this->fee('Tuition', 10000);
        $this->actingAs($this->accountant)->post('/invoices/generate', ['branch_id' => $this->branchA->id, 'grade_id' => $this->grade->id]);
        $invoice = Invoice::firstOrFail();

        $this->actingAs($this->accountant)->patch("/invoices/{$invoice->id}/discount", ['discount_amount' => 1000, 'discount_reason' => 'Sibling discount']);
        $this->assertSame(2, $invoice->journalEntries()->count()); // issuance + discount
        $discountEntry1 = $invoice->journalEntries()->latest('id')->first();
        $this->assertSame(1000.0, $discountEntry1->totalDebit());

        $this->actingAs($this->accountant)->patch("/invoices/{$invoice->id}/discount", ['discount_amount' => 600, 'discount_reason' => 'Adjusted']);
        $this->assertSame(3, $invoice->journalEntries()->count());
        $discountEntry2 = $invoice->journalEntries()->latest('id')->first();
        $this->assertSame(400.0, $discountEntry2->totalDebit(), 'only the 400 ETB delta, not the new 600 total');

        // AR control account should now equal the invoice's own outstanding balance
        $this->assertEqualsWithDelta($invoice->fresh()->balance(), $this->account('1300')->fresh()->balance(), 0.01);
    }

    public function test_waiving_then_removing_the_waiver_posts_and_reverses_correctly(): void
    {
        $this->installAccounting();
        $this->fee('Tuition', 5000);
        $this->actingAs($this->accountant)->post('/invoices/generate', ['branch_id' => $this->branchA->id, 'grade_id' => $this->grade->id]);
        $invoice = Invoice::firstOrFail();

        $this->actingAs($this->accountant)->post("/invoices/{$invoice->id}/toggle-waive", ['reason' => 'Hardship']);
        $this->assertSame(0.0, $this->account('1300')->fresh()->balance(), 'fully waived, nothing left in AR for this invoice');

        $this->actingAs($this->accountant)->post("/invoices/{$invoice->id}/toggle-waive");
        $this->assertSame(5000.0, $this->account('1300')->fresh()->balance(), 'removing the waiver puts the charge back');
    }

    // ---------------------------------------------------------------- payments

    public function test_a_counter_payment_posts_cash_and_ar_matching_the_example_in_the_brief(): void
    {
        $this->installAccounting();
        $this->fee('Tuition', 10000);
        $this->actingAs($this->accountant)->post('/invoices/generate', ['branch_id' => $this->branchA->id, 'grade_id' => $this->grade->id]);
        $invoice = Invoice::firstOrFail();

        $this->actingAs($this->accountant)->post("/invoices/{$invoice->id}/record-payment", [
            'amount' => 4000, 'method' => 'cash', 'paid_by_name' => 'Parent Name',
        ])->assertSessionHasNoErrors();

        $payment = Payment::firstOrFail();
        $entry = $payment->journalEntries()->firstOrFail();
        $this->assertSame(4000.0, $entry->totalDebit());
        $cashLine = $entry->lines->firstWhere('account_id', $this->account('1150')->id); // Undeposited Funds (unmapped payment)
        $arLine = $entry->lines->firstWhere('account_id', $this->account('1300')->id);
        $this->assertSame(4000.0, (float) $cashLine->debit);
        $this->assertSame(4000.0, (float) $arLine->credit);
        $this->assertSame(6000.0, $invoice->fresh()->balance());
        $this->assertSame(6000.0, $this->account('1300')->fresh()->balance(), 'AR control matches the invoice balance exactly');
    }

    public function test_a_pending_portal_payment_posts_nothing_until_verified_and_a_rejected_one_never_posts(): void
    {
        $this->installAccounting();
        $this->fee('Tuition', 1000);
        $this->actingAs($this->accountant)->post('/invoices/generate', ['branch_id' => $this->branchA->id, 'grade_id' => $this->grade->id]);
        $invoice = Invoice::firstOrFail();
        $payment = Payment::create(['invoice_id' => $invoice->id, 'amount' => 500, 'method' => 'bank_transfer', 'reference_number' => 'REF1', 'proof_path' => 'payment-proofs/x.png', 'status' => 'pending', 'submitted_by' => $this->accountant->id]);

        $this->assertSame(0, $payment->journalEntries()->count(), 'nothing posted while merely pending');

        $this->actingAs($this->accountant)->post("/payments/{$payment->id}/reject", ['rejection_reason' => 'Wrong amount']);
        $this->assertSame(0, $payment->journalEntries()->count(), 'a rejected payment never posts anything');

        $second = Payment::create(['invoice_id' => $invoice->id, 'amount' => 500, 'method' => 'bank_transfer', 'reference_number' => 'REF2', 'proof_path' => 'payment-proofs/y.png', 'status' => 'pending', 'submitted_by' => $this->accountant->id]);
        $this->actingAs($this->accountant)->post("/payments/{$second->id}/verify");
        $this->assertSame(1, $second->journalEntries()->count(), 'verifying posts exactly once');
    }

    public function test_two_partial_payments_each_post_their_own_entry_and_the_ar_balance_matches_the_outstanding_total(): void
    {
        $this->installAccounting();
        $this->fee('Tuition', 1000);
        $this->actingAs($this->accountant)->post('/invoices/generate', ['branch_id' => $this->branchA->id, 'grade_id' => $this->grade->id]);
        $invoice = Invoice::firstOrFail();

        $this->actingAs($this->accountant)->post("/invoices/{$invoice->id}/record-payment", ['amount' => 400, 'method' => 'cash', 'paid_by_name' => 'Payer A']);
        $this->actingAs($this->accountant)->post("/invoices/{$invoice->id}/record-payment", ['amount' => 350, 'method' => 'cash', 'paid_by_name' => 'Payer B']);

        $this->assertSame(2, Payment::count());
        $this->assertSame(250.0, $invoice->fresh()->balance());
        $this->assertEqualsWithDelta(250.0, $this->account('1300')->fresh()->balance(), 0.01);
    }

    public function test_a_refund_and_a_reversal_both_post_dr_ar_cr_cash(): void
    {
        $this->installAccounting();
        $this->fee('Tuition', 1000);
        $this->actingAs($this->accountant)->post('/invoices/generate', ['branch_id' => $this->branchA->id, 'grade_id' => $this->grade->id]);
        $invoice = Invoice::firstOrFail();
        $this->actingAs($this->accountant)->post("/invoices/{$invoice->id}/record-payment", ['amount' => 1000, 'method' => 'cash', 'paid_by_name' => 'Payer A']);
        $payment = Payment::firstOrFail();
        $this->assertSame(0.0, $invoice->fresh()->balance());

        $this->actingAs($this->accountant)->post("/payments/{$payment->id}/refund", ['amount' => 300, 'kind' => 'refund', 'reason' => 'Overpaid by mistake']);

        $refund = PaymentRefund::firstOrFail();
        $entry = $refund->journalEntries()->firstOrFail();
        $arLine = $entry->lines->firstWhere('account_id', $this->account('1300')->id);
        $cashLine = $entry->lines->firstWhere('account_id', $this->account('1150')->id);
        $this->assertSame(300.0, (float) $arLine->debit);
        $this->assertSame(300.0, (float) $cashLine->credit);
        $this->assertSame(300.0, $invoice->fresh()->balance());
        $this->assertEqualsWithDelta(300.0, $this->account('1300')->fresh()->balance(), 0.01);
    }

    // ---------------------------------------------------------------- the fee workflow must never break

    public function test_without_a_chart_of_accounts_the_fee_workflow_works_exactly_as_before_and_nothing_posts(): void
    {
        // deliberately NOT calling installAccounting() -- this school has never touched the accounting module
        $this->fee('Tuition', 1000);

        $this->actingAs($this->accountant)->post('/invoices/generate', ['branch_id' => $this->branchA->id, 'grade_id' => $this->grade->id])->assertSessionHasNoErrors();
        $invoice = Invoice::firstOrFail();
        $this->assertSame(0, JournalEntry::count());

        $this->actingAs($this->accountant)->post("/invoices/{$invoice->id}/record-payment", ['amount' => 1000, 'method' => 'cash', 'paid_by_name' => 'Payer A'])->assertSessionHasNoErrors();
        $this->assertSame(0.0, $invoice->fresh()->balance(), 'the fee workflow itself completed normally');
        $this->assertSame(0, JournalEntry::count(), 'still nothing posted -- no chart of accounts exists');

        $payment = Payment::firstOrFail();
        $this->actingAs($this->accountant)->post("/payments/{$payment->id}/refund", ['amount' => 200, 'kind' => 'refund', 'reason' => 'test'])->assertSessionHasNoErrors();
        $this->assertSame(0, JournalEntry::count());
    }

    public function test_a_missing_accounting_period_never_blocks_recording_a_payment(): void
    {
        // chart of accounts installed, but no period covers today -- a real, plausible gap
        ChartOfAccountsTemplate::install($this->school);
        $this->fee('Tuition', 1000);

        $this->actingAs($this->accountant)->post('/invoices/generate', ['branch_id' => $this->branchA->id, 'grade_id' => $this->grade->id])->assertSessionHasNoErrors();
        $invoice = Invoice::firstOrFail();

        $response = $this->actingAs($this->accountant)->post("/invoices/{$invoice->id}/record-payment", ['amount' => 1000, 'method' => 'cash', 'paid_by_name' => 'Payer A']);
        $response->assertSessionHasNoErrors();
        $this->assertSame(0.0, $invoice->fresh()->balance(), 'the payment itself still went through');
        $this->assertSame(0, JournalEntry::count(), 'posting silently could not happen -- no period covers today');
    }
}
