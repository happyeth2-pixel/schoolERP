<?php

namespace App\Notifications;

use App\Models\Invoice;
use App\Support\Localization\NotificationLocale;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class InvoiceGenerated extends Notification
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
        $student = $this->invoice->student;
        $locale = NotificationLocale::for($notifiable);

        return [
            'title' => __('notifications.invoice_generated.title', [], $locale),
            'body' => __('notifications.invoice_generated.body', [
                'student' => $student->fullName(),
                'period' => $period,
                'amount' => number_format((float) $this->invoice->totalAmount(), 2),
            ], $locale),
            'invoice_id' => $this->invoice->id,
        ];
    }
}
