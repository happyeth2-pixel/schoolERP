<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\User;
use App\Notifications\InvoiceOverdue;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/**
 * Daily housekeeping for billing -- the same disclosed gap this school had for library loans
 * (CheckOverdueLoans) and HR contract expiry, now covering unpaid invoices. Needs its own entry in
 * routes/console.php; this command only registers the logic, the scheduler dispatches it.
 *
 * "Overdue" is Invoice::isOverdue() -- a LIVE comparison against the school's own payment_due_days (an
 * owner-set School Settings value, see SchoolSettingsController), never a stored due_date that could
 * drift out of sync with it. Notified once per invoice via `overdue_notified_at`, the same "don't spam"
 * guard LoanOverdue/ContractExpiringSoon already use. The family is never notified here -- this exists
 * so whoever manages invoices in that branch (owner/principal/accountant/registrar) can follow up
 * directly, by phone if needed, the same reasoning LoanOverdue applies to librarians.
 */
class CheckOverdueInvoices extends Command
{
    protected $signature = 'invoices:check-overdue';

    protected $description = 'Notify branch invoice managers about invoices that have just gone overdue.';

    public function handle(): int
    {
        // A loose pre-filter only (period_start always precedes the real due date) -- isOverdue() below
        // is the actual check, against each school's own payment_due_days.
        $candidates = Invoice::where('is_waived', false)
            ->whereNull('overdue_notified_at')
            ->where('period_start', '<', now())
            ->with(['student.school', 'items', 'payments.refunds'])
            ->get();

        $count = 0;
        foreach ($candidates as $invoice) {
            if (! $invoice->isOverdue()) {
                continue;
            }

            $invoice->update(['overdue_notified_at' => now()]);
            $this->notifyManagers($invoice);
            $count++;
            $this->line("overdue: {$invoice->invoice_number} ({$invoice->student->fullName()}, due {$invoice->dueDate()->toDateString()})");
        }

        $this->info("{$count} overdue invoice(s) notified.");

        return self::SUCCESS;
    }

    private function notifyManagers(Invoice $invoice): void
    {
        $student = $invoice->student;

        $recipients = User::where('school_id', $student->school_id)
            ->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $student->branch_id))
            ->whereHas('permissions', fn ($q) => $q->where('name', 'manage-invoices'))
            ->get();

        Notification::send($recipients, new InvoiceOverdue($invoice));
    }
}
