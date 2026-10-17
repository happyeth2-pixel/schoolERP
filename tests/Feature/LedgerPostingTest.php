<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Journal;
use App\Models\JournalEntry;
use App\Services\Ledger;
use App\Support\ChartOfAccountsTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

/** The core invariant this whole engine exists to guarantee: for every POSTED journal entry,
 * SUM(debit) === SUM(credit). Every test here either proves that invariant holds or proves the engine
 * refuses to create a posted entry that would violate it. */
class LedgerPostingTest extends TestCase
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

    private function draft(float $debit, float $credit, string $date = '2026-06-15'): JournalEntry
    {
        return Ledger::createDraft($this->school, [
            'journal_id' => $this->journal->id,
            'entry_date' => $date,
            'description' => 'Test entry',
        ], [
            ['account_id' => $this->cash->id, 'debit' => $debit, 'credit' => 0],
            ['account_id' => $this->revenue->id, 'debit' => 0, 'credit' => $credit],
        ], $this->owner);
    }

    public function test_a_balanced_entry_posts_successfully(): void
    {
        $entry = $this->draft(1000, 1000);
        $posted = Ledger::post($entry, $this->owner);

        $this->assertSame('posted', $posted->status);
        $this->assertSame(1000.0, $posted->totalDebit());
        $this->assertSame(1000.0, $posted->totalCredit());
    }

    public function test_an_unbalanced_entry_cannot_be_posted(): void
    {
        $entry = $this->draft(1000, 900);

        $this->expectException(ValidationException::class);
        Ledger::post($entry, $this->owner);
    }

    public function test_a_zero_total_entry_cannot_be_posted(): void
    {
        $entry = Ledger::createDraft($this->school, [
            'journal_id' => $this->journal->id, 'entry_date' => '2026-06-15',
        ], [], $this->owner);

        $this->expectException(ValidationException::class);
        Ledger::post($entry, $this->owner);
    }

    public function test_a_draft_entry_can_be_edited_but_a_posted_one_cannot(): void
    {
        $entry = $this->draft(1000, 1000);
        Ledger::replaceLines($entry, [
            ['account_id' => $this->cash->id, 'debit' => 500, 'credit' => 0],
            ['account_id' => $this->revenue->id, 'debit' => 0, 'credit' => 500],
        ]);
        $this->assertSame(500.0, $entry->fresh()->totalDebit());

        $posted = Ledger::post($entry->fresh(), $this->owner);

        // Ledger::replaceLines() itself refuses a non-draft entry before ever touching a line row
        try {
            Ledger::replaceLines($posted, [
                ['account_id' => $this->cash->id, 'debit' => 999, 'credit' => 0],
                ['account_id' => $this->revenue->id, 'debit' => 0, 'credit' => 999],
            ]);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException) {
            // expected
        }

        // and even bypassing that service, the line model itself refuses a direct edit or delete --
        // defense in depth, the same "strictest pattern" as PaymentRefund/AuditLog
        $line = $posted->lines->first();
        $this->expectException(\App\Exceptions\DeletionBlockedException::class);
        $line->update(['debit' => 1]);
    }

    public function test_a_posted_entrys_line_cannot_be_deleted_directly_either(): void
    {
        $posted = Ledger::post($this->draft(1000, 1000), $this->owner);

        $this->expectException(\App\Exceptions\DeletionBlockedException::class);
        $posted->lines->first()->delete();
    }

    public function test_a_posted_entry_cannot_be_updated_directly(): void
    {
        $posted = Ledger::post($this->draft(1000, 1000), $this->owner);

        $this->expectException(\App\Exceptions\DeletionBlockedException::class);
        $posted->update(['description' => 'Sneaky edit']);
    }

    public function test_a_posted_entry_cannot_be_deleted_only_reversed(): void
    {
        $posted = Ledger::post($this->draft(1000, 1000), $this->owner);

        $this->expectException(\App\Exceptions\DeletionBlockedException::class);
        $posted->delete();
    }

    public function test_a_draft_entry_can_be_deleted(): void
    {
        $entry = $this->draft(1000, 1000);
        $entry->delete();
        $this->assertNull(JournalEntry::find($entry->id));
    }

    public function test_reversing_a_posted_entry_creates_a_correctly_mirrored_and_linked_entry(): void
    {
        $posted = Ledger::post($this->draft(1000, 1000), $this->owner);

        $reversal = Ledger::reverse($posted, $this->owner, 'Recorded in error');

        $this->assertSame('posted', $reversal->status);
        $this->assertSame(1000.0, $reversal->totalDebit());
        $this->assertSame(1000.0, $reversal->totalCredit());
        // debit and credit sides are swapped relative to the original
        $cashLine = $reversal->lines->firstWhere('account_id', $this->cash->id);
        $revenueLine = $reversal->lines->firstWhere('account_id', $this->revenue->id);
        $this->assertSame(1000.0, (float) $cashLine->credit);
        $this->assertSame(0.0, (float) $cashLine->debit);
        $this->assertSame(1000.0, (float) $revenueLine->debit);

        $this->assertSame($reversal->id, $posted->fresh()->reversed_by_entry_id);
        $this->assertSame($posted->id, $reversal->reverses_entry_id);
        $this->assertTrue($posted->fresh()->isReversed());

        // the original entry itself is untouched and still posted -- history is never rewritten
        $this->assertSame('posted', $posted->fresh()->status);
        $this->assertSame(1000.0, $posted->fresh()->totalDebit());

        // net effect on the cash account is now zero
        $this->assertSame(0.0, $this->cash->fresh()->balance());
    }

    public function test_a_journal_entry_cannot_be_reversed_twice(): void
    {
        $posted = Ledger::post($this->draft(1000, 1000), $this->owner);
        Ledger::reverse($posted, $this->owner);

        $this->expectException(ValidationException::class);
        Ledger::reverse($posted->fresh(), $this->owner);
    }

    public function test_a_draft_entry_cannot_be_reversed(): void
    {
        $entry = $this->draft(1000, 1000);

        $this->expectException(ValidationException::class);
        Ledger::reverse($entry, $this->owner);
    }

    public function test_a_header_account_cannot_receive_a_posting(): void
    {
        $assets = Account::where('school_id', $this->school->id)->where('code', '1000')->firstOrFail();
        $entry = Ledger::createDraft($this->school, [
            'journal_id' => $this->journal->id, 'entry_date' => '2026-06-15',
        ], [
            ['account_id' => $assets->id, 'debit' => 500, 'credit' => 0],
            ['account_id' => $this->revenue->id, 'debit' => 0, 'credit' => 500],
        ], $this->owner);

        $this->expectException(ValidationException::class);
        Ledger::post($entry, $this->owner);
    }

    public function test_an_inactive_account_cannot_receive_a_posting(): void
    {
        $this->revenue->update(['is_active' => false]);
        $entry = $this->draft(500, 500);

        $this->expectException(ValidationException::class);
        Ledger::post($entry, $this->owner);
    }

    public function test_a_draft_cannot_be_created_for_a_date_no_period_covers_at_all(): void
    {
        $this->expectException(ValidationException::class);
        $this->draft(500, 500, '2030-01-01'); // no period, open or closed, covers this date
    }

    public function test_a_draft_can_be_created_for_a_date_whose_period_is_closed_but_posting_it_is_refused(): void
    {
        AccountingPeriod::where('school_id', $this->school->id)->update(['status' => 'closed']);

        // drafting doesn't move money yet, so it's allowed even though the period is closed...
        $entry = $this->draft(500, 500);
        $this->assertSame('draft', $entry->status);

        // ...but posting it is refused until the period is reopened
        $this->expectException(ValidationException::class);
        Ledger::post($entry, $this->owner);
    }

    public function test_posting_into_a_closed_period_is_refused(): void
    {
        $entry = $this->draft(500, 500);
        $entry->accountingPeriod->update(['status' => 'closed']);

        $this->expectException(ValidationException::class);
        Ledger::post($entry, $this->owner);
    }

    public function test_reopening_a_closed_period_allows_posting_into_it_again(): void
    {
        $entry = $this->draft(500, 500);
        $period = $entry->accountingPeriod;
        $period->update(['status' => 'closed']);
        $period->update(['status' => 'open']);

        $reopened = Ledger::post($entry->fresh(), $this->owner);
        $this->assertSame('posted', $reopened->status);
    }

    public function test_a_branch_scoped_account_cannot_be_used_by_a_different_branchs_entry(): void
    {
        $branchACash = Account::create(['school_id' => $this->school->id, 'branch_id' => $this->branchA->id, 'code' => '1101', 'name' => 'Branch A Cash', 'type' => 'asset', 'normal_balance' => 'debit']);

        $entry = Ledger::createDraft($this->school, [
            'journal_id' => $this->journal->id, 'entry_date' => '2026-06-15', 'branch_id' => $this->branchB->id,
        ], [
            ['account_id' => $branchACash->id, 'debit' => 500, 'credit' => 0],
            ['account_id' => $this->revenue->id, 'debit' => 0, 'credit' => 500],
        ], $this->owner);

        $this->expectException(ValidationException::class);
        Ledger::post($entry, $this->owner);
    }

    public function test_only_accounting_manage_can_post_or_reverse_an_entry_via_http(): void
    {
        $entry = $this->draft(500, 500);
        $bystander = $this->makeUser('accountant', null, []);

        $this->actingAs($bystander)->post("/accounting/journal-entries/{$entry->id}/post")->assertForbidden();

        $manager = $this->makeUser('accountant', null, ['accounting-manage']);
        $this->actingAs($manager)->post("/accounting/journal-entries/{$entry->id}/post")->assertSessionHasNoErrors();
        $this->assertSame('posted', $entry->fresh()->status);

        $this->actingAs($bystander)->post("/accounting/journal-entries/{$entry->id}/reverse")->assertForbidden();
    }

    public function test_every_posted_entry_in_the_database_has_equal_debits_and_credits(): void
    {
        // create a handful of entries of different shapes (including a multi-line one and a reversal)
        Ledger::post($this->draft(1000, 1000), $this->owner);
        Ledger::post($this->draft(250.50, 250.50), $this->owner);
        $toReverse = Ledger::post($this->draft(75, 75), $this->owner);
        Ledger::reverse($toReverse, $this->owner);

        $multi = Ledger::createDraft($this->school, [
            'journal_id' => $this->journal->id, 'entry_date' => '2026-06-15',
        ], [
            ['account_id' => $this->cash->id, 'debit' => 300, 'credit' => 0],
            ['account_id' => $this->revenue->id, 'debit' => 0, 'credit' => 200],
            ['account_id' => $this->revenue->id, 'debit' => 0, 'credit' => 100],
        ], $this->owner);
        Ledger::post($multi, $this->owner);

        $posted = JournalEntry::where('status', 'posted')->with('lines')->get();
        $this->assertGreaterThanOrEqual(5, $posted->count());
        foreach ($posted as $entry) {
            $this->assertEqualsWithDelta($entry->totalDebit(), $entry->totalCredit(), 0.001, "Entry {$entry->entry_number} is unbalanced");
        }
    }
}
