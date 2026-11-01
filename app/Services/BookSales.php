<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\BookItem;
use App\Models\Invoice;
use App\Models\NumberSequence;
use App\Models\Student;
use App\Models\StudentBookPurchase;
use App\Models\User;
use App\Notifications\InvoiceGenerated;
use App\Support\SchoolCalendar;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Selling specific books to a specific student -- used by the student's own profile. A book is sold at
 * most once per student (student_book_purchases.unique, re-checked here too): the picker shown to staff
 * already excludes what a student owns, but this is the real authority, same as every other "is this
 * already true" check in the app.
 *
 * Unlike a recurring fee or optional service, a book sale is never tied to a billing period -- it is
 * billed the moment it happens, onto a 'one_time' invoice for today (one per student per day; several
 * books bought in the same visit land on the same invoice, several visits on different days get their
 * own). See the 2026_10_29_110000 migration's docblock for why 'one_time' exists as its own frequency
 * rather than reusing 'annual'/'termly'/'monthly'.
 */
class BookSales
{
    /** @return array{invoice: Invoice, purchased: list<string>} */
    public static function sell(User $actor, Student $student, Collection $books): array
    {
        if ($books->isEmpty()) {
            throw ValidationException::withMessages(['book_item_ids' => 'Choose at least one book.']);
        }

        $alreadyOwned = StudentBookPurchase::where('student_id', $student->id)
            ->whereIn('book_item_id', $books->pluck('id'))
            ->exists();
        if ($alreadyOwned) {
            throw ValidationException::withMessages(['book_item_ids' => 'One of the chosen books has already been sold to this student.']);
        }

        [$invoice, $purchased, $justCreated] = DB::transaction(function () use ($actor, $student, $books) {
            $invoice = self::todaysInvoice($student);
            $justCreated = $invoice->wasRecentlyCreated;
            $purchased = [];

            foreach ($books as $book) {
                $item = $invoice->addLineFrom($book);

                StudentBookPurchase::create([
                    'student_id' => $student->id,
                    'book_item_id' => $book->id,
                    'invoice_item_id' => $item->id,
                    'purchased_on' => SchoolCalendar::today()->toDateString(),
                    'price_at_sale' => $book->price,
                ]);

                $purchased[] = $book->title;
            }

            AuditLog::record($actor, 'student.books_sold', $student, null, [
                'books' => $purchased,
                'invoice' => $invoice->invoice_number,
            ]);

            return [$invoice, $purchased, $justCreated];
        });

        // Only on the invoice's first creation -- same as InvoiceController::billPeriod(). A second book
        // sold to the same student later today adds a line to the SAME invoice (see todaysInvoice()) and
        // deliberately does not notify or re-post again: one notification and one ledger entry per
        // invoice, not per line, exactly like every other invoice in the app.
        if ($justCreated) {
            $student->guardians->each(fn ($guardian) => $guardian->notify(new InvoiceGenerated($invoice)));

            try {
                AccountingPostings::postInvoiceIssued($invoice, $actor);
            } catch (\Throwable $e) {
                Log::warning('Accounting: failed to post invoice-issued entry for a book sale', ['invoice_id' => $invoice->id, 'error' => $e->getMessage()]);
            }
        }

        return ['invoice' => $invoice, 'purchased' => $purchased];
    }

    /** Today's one-off invoice for this student, reused if books were already sold to them today, created
     *  otherwise. Mirrors StudentServices::currentPeriodInvoice()'s shape, but there is only one "period":
     *  today. */
    private static function todaysInvoice(Student $student): Invoice
    {
        $today = SchoolCalendar::today()->toDateString();
        $academicYearId = $student->currentEnrollment?->section->academic_year_id ?? $student->school->academicYears()->where('is_current', true)->value('id');

        // whereDate(), not a plain where(): 'period_start' is a date-cast column, and a plain equality
        // compares against however the connection happens to have serialised it (a MySQL DATE column
        // tolerates this, but it is not something to depend on) -- same reasoning StudentServices::
        // currentPeriodInvoice() already applies to this same column.
        $invoice = Invoice::where('student_id', $student->id)
            ->where('academic_year_id', $academicYearId)
            ->where('frequency', 'one_time')
            ->whereDate('period_start', $today)
            ->first();

        if ($invoice) {
            $invoice->wasRecentlyCreated = false;

            return $invoice;
        }

        $invoice = Invoice::create([
            'student_id' => $student->id,
            'academic_year_id' => $academicYearId,
            'frequency' => 'one_time',
            'period_start' => $today,
            'period_label' => 'Books',
            'invoice_number' => self::nextInvoiceNumber($student),
        ]);
        $invoice->wasRecentlyCreated = true;

        return $invoice;
    }

    /** Same counter, same format, same school-scoped sequence key ('invoice') as every other invoice
     *  (InvoiceController::nextInvoiceNumber) -- a book sale's invoice number is drawn from the one
     *  shared sequence, not a parallel one, so numbers never collide or look like a different system. */
    private static function nextInvoiceNumber(Student $student): string
    {
        $year = (int) SchoolCalendar::today()->year;
        $schoolId = $student->school_id;
        $prefix = "INV-{$year}-";

        $n = NumberSequence::next($schoolId, 'invoice', $year, fn () => Invoice::whereHas('student', fn ($q) => $q->where('school_id', $schoolId))
            ->where('invoice_number', 'like', "{$prefix}%")
            ->pluck('invoice_number')
            ->map(fn ($x) => (int) substr($x, strlen($prefix)))
            ->max() ?? 0);

        return $prefix.str_pad((string) $n, 6, '0', STR_PAD_LEFT);
    }
}
