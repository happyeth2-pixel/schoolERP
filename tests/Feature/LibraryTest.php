<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\BookCopy;
use App\Models\LibraryLoan;
use App\Models\Student;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

/**
 * Academic expansion, Phase 6: the Library. The brief's own words: "issue/return/renew/lost/damaged
 * tracking and fines NOT part of tuition unless explicitly configured" -- confirmed at the start of this
 * whole initiative that fines stay self-contained within Library, never an Invoice.
 */
class LibraryTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    private Book $book;

    private BookCopy $copy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchool();
        Carbon::setTestNow('2026-10-10 09:00:00');
        $this->book = Book::create(['school_id' => $this->school->id, 'title' => 'Things Fall Apart', 'created_by' => $this->owner->id]);
        $this->copy = BookCopy::create(['book_id' => $this->book->id, 'branch_id' => $this->branchA->id, 'copy_number' => 'C-1', 'created_by' => $this->owner->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function librarian(): User
    {
        return $this->makeUser('branch_admin', $this->branchA->id, ['library-manage']);
    }

    public function test_someone_without_library_manage_cannot_add_a_book_or_copy(): void
    {
        $viewer = $this->makeUser('branch_admin', $this->branchA->id, ['library-view']);

        $this->actingAs($viewer)->post('/library/books', ['title' => 'x'])->assertForbidden();
        $this->actingAs($viewer)->post("/library/books/{$this->book->id}/copies", ['branch_id' => $this->branchA->id, 'copy_number' => 'C-2'])->assertForbidden();
        $this->actingAs($viewer)->get('/library/books')->assertOk();
    }

    public function test_a_book_cannot_be_removed_while_it_has_copies(): void
    {
        $this->actingAs($this->owner)->delete("/library/books/{$this->book->id}")->assertSessionHasErrors('delete');
        $this->assertSame(1, Book::count());
    }

    public function test_a_copy_cannot_be_removed_once_it_has_loan_history(): void
    {
        $librarian = $this->librarian();
        $this->actingAs($librarian)->post('/library/issue', [
            'book_copy_id' => $this->copy->id, 'borrower_type' => Student::class, 'borrower_id' => $this->studentA->id,
        ]);
        $loan = LibraryLoan::firstOrFail();
        $this->actingAs($librarian)->post("/library/loans/{$loan->id}/return");

        $this->actingAs($librarian)->delete("/library/books/{$this->book->id}/copies/{$this->copy->id}")->assertSessionHasErrors('delete');
        $this->assertSame(1, BookCopy::count());
    }

    public function test_issuing_to_a_student_sets_due_date_and_copy_status(): void
    {
        $librarian = $this->librarian();

        $this->actingAs($librarian)->post('/library/issue', [
            'book_copy_id' => $this->copy->id, 'borrower_type' => Student::class, 'borrower_id' => $this->studentA->id,
        ])->assertSessionHasNoErrors();

        $loan = LibraryLoan::firstOrFail();
        $this->assertSame('active', $loan->status);
        $this->assertSame(Student::class, $loan->borrower_type);
        $this->assertSame($this->studentA->id, $loan->borrower_id);
        $this->assertSame('2026-10-24', $loan->due_date->toDateString(), '14 days, the default loan period');
        $this->assertSame('issued', $this->copy->fresh()->status);
    }

    public function test_issuing_to_a_staff_member_works_too(): void
    {
        $librarian = $this->librarian();
        $teacher = $this->makeUser('teacher', $this->branchA->id, []);

        $this->actingAs($librarian)->post('/library/issue', [
            'book_copy_id' => $this->copy->id, 'borrower_type' => User::class, 'borrower_id' => $teacher->id,
        ])->assertSessionHasNoErrors();

        $loan = LibraryLoan::firstOrFail();
        $this->assertSame(User::class, $loan->borrower_type);
        $this->assertSame($teacher->name, $loan->borrowerName());
    }

    public function test_a_copy_that_is_not_available_cannot_be_issued_again(): void
    {
        $librarian = $this->librarian();
        $this->copy->update(['status' => 'issued']);

        $this->actingAs($librarian)->post('/library/issue', [
            'book_copy_id' => $this->copy->id, 'borrower_type' => Student::class, 'borrower_id' => $this->studentA->id,
        ])->assertSessionHasErrors('book_copy_id');
        $this->assertSame(0, LibraryLoan::count());
    }

    public function test_returning_on_time_has_no_fine_even_with_a_rate_configured(): void
    {
        $this->school->update(['library_fine_per_day' => 5]);
        $librarian = $this->librarian();
        $this->actingAs($librarian)->post('/library/issue', [
            'book_copy_id' => $this->copy->id, 'borrower_type' => Student::class, 'borrower_id' => $this->studentA->id,
        ]);
        $loan = LibraryLoan::firstOrFail();

        $this->actingAs($librarian)->post("/library/loans/{$loan->id}/return")->assertSessionHasNoErrors();

        $loan->refresh();
        $this->assertSame('returned', $loan->status);
        $this->assertNull($loan->fine_amount);
        $this->assertSame('available', $this->copy->fresh()->status);
    }

    public function test_returning_late_computes_a_fine_only_when_a_rate_is_configured(): void
    {
        $librarian = $this->librarian();
        $this->actingAs($librarian)->post('/library/issue', [
            'book_copy_id' => $this->copy->id, 'borrower_type' => Student::class, 'borrower_id' => $this->studentA->id,
        ]);
        $loan = LibraryLoan::firstOrFail();

        Carbon::setTestNow('2026-10-29 09:00:00'); // 5 days late (due 2026-10-24)
        $this->actingAs($librarian)->post("/library/loans/{$loan->id}/return");
        $this->assertNull($loan->fresh()->fine_amount, 'no rate configured -- never a fine, not even zero');

        LibraryLoan::whereKey($loan->id)->update(['returned_at' => null, 'status' => 'active']);
        $this->copy->update(['status' => 'issued']);
        $this->school->update(['library_fine_per_day' => 10]);
        $this->actingAs($librarian)->post("/library/loans/{$loan->id}/return")->assertSessionHasNoErrors();
        $this->assertSame('50.00', $loan->fresh()->fine_amount, '5 days late x 10');
    }

    public function test_renewing_extends_the_due_date_and_counts_renewals(): void
    {
        $librarian = $this->librarian();
        $this->actingAs($librarian)->post('/library/issue', [
            'book_copy_id' => $this->copy->id, 'borrower_type' => Student::class, 'borrower_id' => $this->studentA->id,
        ]);
        $loan = LibraryLoan::firstOrFail();

        $this->actingAs($librarian)->post("/library/loans/{$loan->id}/renew")->assertSessionHasNoErrors();

        $loan->refresh();
        $this->assertSame(1, $loan->renewed_count);
        $this->assertSame('2026-10-24', $loan->due_date->toDateString(), 'today + 14, not the old due date + 14');
    }

    public function test_marking_a_loan_lost_closes_it_and_marks_the_copy(): void
    {
        $librarian = $this->librarian();
        $this->actingAs($librarian)->post('/library/issue', [
            'book_copy_id' => $this->copy->id, 'borrower_type' => Student::class, 'borrower_id' => $this->studentA->id,
        ]);
        $loan = LibraryLoan::firstOrFail();

        $this->actingAs($librarian)->post("/library/loans/{$loan->id}/lost-or-damaged", [
            'status' => 'lost', 'fine_amount' => 500, 'reason' => 'Never returned after repeated reminders.',
        ])->assertSessionHasNoErrors();

        $loan->refresh();
        $this->assertSame('lost', $loan->status);
        $this->assertSame('500.00', $loan->fine_amount);
        $this->assertSame('lost', $this->copy->fresh()->status);
    }

    public function test_waiving_and_paying_a_fine_never_touches_invoices_or_payments(): void
    {
        $librarian = $this->librarian();
        $loan = LibraryLoan::create([
            'book_copy_id' => $this->copy->id, 'borrower_type' => Student::class, 'borrower_id' => $this->studentA->id,
            'issued_by' => $librarian->id, 'issued_at' => '2026-10-01', 'due_date' => '2026-10-05',
            'returned_at' => '2026-10-10', 'status' => 'returned', 'fine_amount' => 25,
        ]);

        $this->actingAs($librarian)->post("/library/loans/{$loan->id}/waive-fine")->assertSessionHasNoErrors();
        $this->assertNotNull($loan->fresh()->fine_waived_at);

        $loan->update(['fine_waived_at' => null]);
        $this->actingAs($librarian)->post("/library/loans/{$loan->id}/pay-fine")->assertSessionHasNoErrors();
        $this->assertNotNull($loan->fresh()->fine_paid_at);

        $this->assertSame(0, \App\Models\Invoice::count(), 'a library fine never creates an invoice');
    }

    public function test_branch_scoping_refuses_a_copy_or_loan_outside_scope(): void
    {
        $copyB = BookCopy::create(['book_id' => $this->book->id, 'branch_id' => $this->branchB->id, 'copy_number' => 'C-B', 'created_by' => $this->owner->id]);
        $librarianA = $this->makeUser('branch_admin', $this->branchA->id, ['library-manage']);

        $this->actingAs($librarianA)->post('/library/issue', [
            'book_copy_id' => $copyB->id, 'borrower_type' => Student::class, 'borrower_id' => $this->studentB->id,
        ])->assertNotFound();

        $librarianB = $this->makeUser('branch_admin', $this->branchB->id, ['library-manage']);
        $this->actingAs($librarianB)->post('/library/issue', [
            'book_copy_id' => $copyB->id, 'borrower_type' => Student::class, 'borrower_id' => $this->studentB->id,
        ])->assertSessionHasNoErrors();
        $loan = LibraryLoan::firstOrFail();

        $this->actingAs($librarianA)->post("/library/loans/{$loan->id}/return")->assertForbidden();
    }

    public function test_an_auditor_can_view_but_never_write(): void
    {
        $auditor = $this->makeUser('auditor', null, []);

        $this->actingAs($auditor)->get('/library')->assertOk();
        $this->actingAs($auditor)->get('/library/books')->assertOk();
        $this->actingAs($auditor)->post('/library/books', ['title' => 'x'])->assertForbidden();
    }

    public function test_my_library_loans_shows_only_the_signed_in_persons_own_loans(): void
    {
        $librarian = $this->librarian();
        $teacherA = $this->makeUser('teacher', $this->branchA->id, []);
        $teacherB = $this->makeUser('teacher', $this->branchA->id, []);
        $copy2 = BookCopy::create(['book_id' => $this->book->id, 'branch_id' => $this->branchA->id, 'copy_number' => 'C-2', 'created_by' => $this->owner->id]);

        $this->actingAs($librarian)->post('/library/issue', ['book_copy_id' => $this->copy->id, 'borrower_type' => User::class, 'borrower_id' => $teacherA->id]);
        $this->actingAs($librarian)->post('/library/issue', ['book_copy_id' => $copy2->id, 'borrower_type' => User::class, 'borrower_id' => $teacherB->id]);

        $p = $this->props($this->actingAs($teacherA)->get('/my-library-loans'));
        $this->assertCount(1, $p['loans']);
        $this->assertSame('Things Fall Apart', $p['loans'][0]['book_title']);
    }

    // ---------------------------------------------------------------- overdue reminder (§9 hardening)

    public function test_check_overdue_loans_notifies_library_manage_holders_exactly_once(): void
    {
        $librarian = $this->librarian();
        $otherBranchLibrarian = $this->makeUser('branch_admin', $this->branchB->id, ['library-manage']);
        $viewerOnly = $this->makeUser('branch_admin', $this->branchA->id, ['library-view']);

        $loan = LibraryLoan::create([
            'book_copy_id' => $this->copy->id, 'borrower_type' => Student::class, 'borrower_id' => $this->studentA->id,
            'issued_by' => $librarian->id, 'issued_at' => '2026-10-01', 'due_date' => '2026-10-05', 'status' => 'active',
        ]);

        $this->artisan('library:check-overdue-loans')->assertSuccessful();

        $loan->refresh();
        $this->assertNotNull($loan->overdue_notified_at);
        $this->assertCount(1, $librarian->notifications, 'the branch librarian is notified');
        $this->assertSame(\App\Notifications\LoanOverdue::class, $librarian->notifications->first()->type);
        $this->assertCount(0, $otherBranchLibrarian->notifications, 'a different branch is not notified');
        $this->assertCount(0, $viewerOnly->notifications, 'library-view alone is not enough');

        // running it again must not re-notify (the guard is set)
        $this->artisan('library:check-overdue-loans');
        $this->assertCount(1, $librarian->fresh()->notifications);
    }

    public function test_check_overdue_loans_skips_what_is_not_actually_overdue_yet_or_already_closed(): void
    {
        $librarian = $this->librarian();
        LibraryLoan::create([ // not yet due
            'book_copy_id' => $this->copy->id, 'borrower_type' => Student::class, 'borrower_id' => $this->studentA->id,
            'issued_by' => $librarian->id, 'issued_at' => '2026-10-09', 'due_date' => '2026-10-24', 'status' => 'active',
        ]);
        $copy2 = BookCopy::create(['book_id' => $this->book->id, 'branch_id' => $this->branchA->id, 'copy_number' => 'C-2', 'created_by' => $this->owner->id]);
        LibraryLoan::create([ // overdue date, but already returned
            'book_copy_id' => $copy2->id, 'borrower_type' => Student::class, 'borrower_id' => $this->studentA->id,
            'issued_by' => $librarian->id, 'issued_at' => '2026-10-01', 'due_date' => '2026-10-05', 'returned_at' => '2026-10-08', 'status' => 'returned',
        ]);

        $this->artisan('library:check-overdue-loans');

        $this->assertCount(0, $librarian->fresh()->notifications);
    }

    public function test_the_loan_overdue_notification_links_to_the_library_index(): void
    {
        $librarian = $this->librarian();
        LibraryLoan::create([
            'book_copy_id' => $this->copy->id, 'borrower_type' => Student::class, 'borrower_id' => $this->studentA->id,
            'issued_by' => $librarian->id, 'issued_at' => '2026-10-01', 'due_date' => '2026-10-05', 'status' => 'active',
        ]);

        $this->artisan('library:check-overdue-loans');

        $presented = \App\Support\NotificationPresenter::present($librarian->fresh()->notifications->first());
        $this->assertSame(route('library.index'), $presented['action_url']);
    }

    public function test_renewing_an_overdue_loan_resets_the_guard_so_a_later_overdue_crossing_notifies_again(): void
    {
        $librarian = $this->librarian();
        $loan = LibraryLoan::create([
            'book_copy_id' => $this->copy->id, 'borrower_type' => Student::class, 'borrower_id' => $this->studentA->id,
            'issued_by' => $librarian->id, 'issued_at' => '2026-10-01', 'due_date' => '2026-10-05', 'status' => 'active',
            'overdue_notified_at' => '2026-10-06 06:45:00',
        ]);

        $this->actingAs($librarian)->post("/library/loans/{$loan->id}/renew")->assertSessionHasNoErrors();

        $this->assertNull($loan->fresh()->overdue_notified_at, 'a fresh due date deserves a fresh chance to notify if it is missed too');
    }
}
