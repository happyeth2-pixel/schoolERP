<?php

namespace App\Services;

use App\Models\BookCopy;
use App\Models\LibraryLoan;
use App\Models\User;
use App\Support\SchoolCalendar;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The one gateway for every copy/loan state change -- issue, return, renew, lost, damaged, fines -- the
 * same single-writer-service discipline `Stock`/`Ledger`/`SectionCapacity` already established elsewhere in
 * this app. Academic expansion, Phase 6.
 */
class Library
{
    public static function issue(BookCopy $copy, Model $borrower, User $staff, ?string $dueDate = null): LibraryLoan
    {
        return DB::transaction(function () use ($copy, $borrower, $staff, $dueDate) {
            $locked = BookCopy::whereKey($copy->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'available') {
                throw ValidationException::withMessages(['book_copy_id' => 'This copy is not available to issue.']);
            }

            $today = SchoolCalendar::today()->toDateString();
            $loan = LibraryLoan::create([
                'book_copy_id' => $locked->id,
                'borrower_type' => $borrower::class,
                'borrower_id' => $borrower->id,
                'issued_by' => $staff->id,
                'issued_at' => $today,
                'due_date' => $dueDate ?? SchoolCalendar::today()->addDays($staff->school->library_loan_days)->toDateString(),
                'status' => 'active',
            ]);

            $locked->update(['status' => 'issued']);

            return $loan;
        });
    }

    public static function returnCopy(LibraryLoan $loan): LibraryLoan
    {
        return DB::transaction(function () use ($loan) {
            $locked = LibraryLoan::whereKey($loan->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'active', 422, 'This loan is already closed.');

            $today = SchoolCalendar::today();
            $fine = self::calculateFine($locked, $today->toDateString());

            $locked->update(['returned_at' => $today->toDateString(), 'status' => 'returned', 'fine_amount' => $fine]);
            $locked->bookCopy->update(['status' => 'available']);

            return $locked->fresh();
        });
    }

    public static function renew(LibraryLoan $loan): LibraryLoan
    {
        return DB::transaction(function () use ($loan) {
            $locked = LibraryLoan::whereKey($loan->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'active', 422, 'Only an active loan can be renewed.');

            $days = $locked->bookCopy->book->school->library_loan_days;
            $locked->update([
                'due_date' => SchoolCalendar::today()->addDays($days)->toDateString(),
                'renewed_count' => $locked->renewed_count + 1,
                // if this loan was overdue and already notified, renewing it is a clean slate -- a later
                // overdue crossing on the new due date is a new event, worth notifying about again.
                'overdue_notified_at' => null,
            ]);

            return $locked->fresh();
        });
    }

    /** The copy was lost or damaged while on loan -- closes the loan and marks the copy, with an optional
     * flat fee a librarian types in by hand (never auto-derived from a replacement-cost field nothing asked for). */
    public static function markLoanLostOrDamaged(LibraryLoan $loan, string $status, ?float $fineAmount, ?string $reason): LibraryLoan
    {
        abort_unless(in_array($status, ['lost', 'damaged'], true), 422);

        return DB::transaction(function () use ($loan, $status, $fineAmount, $reason) {
            $locked = LibraryLoan::whereKey($loan->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'active', 422, 'Only an active loan can be marked lost or damaged.');

            $locked->update(['status' => $status, 'fine_amount' => $fineAmount, 'notes' => $reason]);
            $locked->bookCopy->update(['status' => $status]);

            return $locked->fresh();
        });
    }

    /** A copy found lost/damaged on the shelf, with no active loan to close. */
    public static function markCopyLostOrDamaged(BookCopy $copy, string $status): BookCopy
    {
        abort_unless(in_array($status, ['lost', 'damaged'], true), 422);
        abort_unless($copy->status === 'available', 422, 'This copy is currently on loan -- close its loan instead.');

        $copy->update(['status' => $status]);

        return $copy->fresh();
    }

    /** Back on the shelf (e.g. a "lost" copy was found, or a "damaged" one was repaired). */
    public static function restoreCopy(BookCopy $copy): BookCopy
    {
        abort_unless(in_array($copy->status, ['lost', 'damaged'], true), 422);

        $copy->update(['status' => 'available']);

        return $copy->fresh();
    }

    public static function waiveFine(LibraryLoan $loan): LibraryLoan
    {
        $loan->update(['fine_waived_at' => now()]);

        return $loan->fresh();
    }

    public static function payFine(LibraryLoan $loan): LibraryLoan
    {
        $loan->update(['fine_paid_at' => now()]);

        return $loan->fresh();
    }

    /** Days overdue x the school's own per-day rate -- null (no fine, ever) when the rate isn't configured,
     * the brief's own "not part of tuition unless explicitly configured" default being OFF, not zero. */
    public static function calculateFine(LibraryLoan $loan, string $asOfDate): ?float
    {
        $rate = $loan->bookCopy->book->school->library_fine_per_day;
        if ($rate === null) {
            return null;
        }

        $daysLate = $loan->due_date->diffInDays($asOfDate, false);

        return $daysLate > 0 ? round($daysLate * (float) $rate, 2) : null;
    }
}
