<?php

namespace App\Console\Commands;

use App\Models\LibraryLoan;
use App\Models\User;
use App\Notifications\LoanOverdue;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/**
 * Daily housekeeping for library loans -- needs its own Render Cron Job dispatch exactly like
 * `hr:check-contract-expiry`, via the `schoolerp-scheduler` Cron Job running `php artisan schedule:run`
 * (see DISASTER_RECOVERY.md); this command only registers the task in routes/console.php, it doesn't invoke
 * itself. Library had no overdue reminder of any kind before this -- a disclosed, real gap, not a missing
 * feature invented here from nothing.
 *
 * Notifies every `library-manage` holder in the loan's own branch (plus org-wide holders) exactly once per
 * loan, guarded by `overdue_notified_at` -- the same "don't spam" rule the HR contract-expiry check and
 * Inventory's low-stock alert both already follow. The borrower is never notified directly: a Student
 * borrower has no login at all, and a staff borrower already sees their own overdue items on My Library
 * Loans -- this exists so the librarian can follow up, not to replace that page.
 */
class CheckOverdueLoans extends Command
{
    protected $signature = 'library:check-overdue-loans';

    protected $description = 'Notify librarians about active loans that have just gone overdue.';

    public function handle(): int
    {
        $today = now()->toDateString();

        $justOverdue = LibraryLoan::where('status', 'active')
            ->whereNull('overdue_notified_at')
            ->where('due_date', '<', $today)
            ->with('bookCopy.book.school')
            ->get();

        foreach ($justOverdue as $loan) {
            $loan->update(['overdue_notified_at' => now()]);
            $this->notifyLibrarians($loan);
            $this->line("overdue: {$loan->bookCopy->book->title} ({$loan->borrowerName()}, due {$loan->due_date->toDateString()})");
        }

        $this->info($justOverdue->count().' overdue loan(s) notified.');

        return self::SUCCESS;
    }

    private function notifyLibrarians(LibraryLoan $loan): void
    {
        $copy = $loan->bookCopy;
        $school = $copy->book->school;

        $recipients = User::where('school_id', $school->id)
            ->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $copy->branch_id))
            ->whereHas('permissions', fn ($q) => $q->where('name', 'library-manage'))
            ->get();

        Notification::send($recipients, new LoanOverdue($loan));
    }
}
