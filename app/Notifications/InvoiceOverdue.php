<?php

namespace App\Notifications;

use App\Models\Invoice;
use App\Support\Localization\NotificationLocale;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** In-app only, to whoever holds manage-invoices in the invoice's own branch (plus org-wide holders),
 * the moment it crosses its due date -- see CheckOverdueInvoices. Sent once per invoice, guarded by
 * `overdue_notified_at`, the same "don't spam" rule LoanOverdue already follows. The family is never
 * notified here: this exists so the branch's own accountant/registrar/principal can follow up directly
 * (by phone if needed), not to replace a reminder to them. */
class InvoiceOverdue extends Notification
{
    use Queueable;

    public function __construct(
        public Invoice $invoice,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $period = $this->invoice->period_label ? " ({$this->invoice->period_label})" : '';
        $locale = NotificationLocale::for($notifiable);

        return [
            'title' => __('notifications.invoice_overdue.title', [], $locale),
            'body' => __('notifications.invoice_overdue.body', [
                'student' => $this->invoice->student->fullName(),
                'period' => $period,
                'balance' => number_format($this->invoice->balance(), 2),
                'date' => $this->invoice->dueDate()->toDateString(),
            ], $locale),
            'invoice_id' => $this->invoice->id,
        ];
    }
}
