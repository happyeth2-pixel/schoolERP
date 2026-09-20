<?php

namespace App\Notifications;

use App\Models\Invoice;
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

        return [
            'title' => 'New invoice',
            'body' => "New invoice for {$student->fullName()}{$period}: ".number_format((float) $this->invoice->totalAmount(), 2).' ETB due.',
            'invoice_id' => $this->invoice->id,
        ];
    }
}
