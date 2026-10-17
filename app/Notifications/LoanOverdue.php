<?php

namespace App\Notifications;

use App\Models\LibraryLoan;
use App\Support\Localization\NotificationLocale;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** In-app only. Sent once per loan (see the LibraryCheckOverdueLoans command's `overdue_notified_at` guard)
 * to whoever holds library-manage in the copy's own branch (plus org-wide holders), the moment a loan
 * crosses its due date -- never repeated daily after that, and reset (so it can fire again) only if the
 * loan is renewed and later goes overdue a second time. Borrowers are never notified directly here: a
 * Student borrower has no login at all, and a staff borrower's own overdue items are already visible on
 * their My Library Loans page -- this notification exists for the librarian to follow up, not to replace
 * that page. */
class LoanOverdue extends Notification
{
    use Queueable;

    public function __construct(
        public LibraryLoan $loan,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $locale = NotificationLocale::for($notifiable);

        return [
            'title' => __('notifications.loan_overdue.title', [], $locale),
            'body' => __('notifications.loan_overdue.body', [
                'title' => $this->loan->bookCopy->book->title,
                'borrower' => $this->loan->borrowerName(),
                'date' => $this->loan->due_date->toDateString(),
            ], $locale),
            'loan_id' => $this->loan->id,
        ];
    }
}
