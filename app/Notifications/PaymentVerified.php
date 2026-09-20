<?php

namespace App\Notifications;

use App\Models\Payment;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class PaymentVerified extends Notification
{
    use Queueable;

    public function __construct(
        public Payment $payment,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Payment confirmed',
            'body' => 'Your payment of '.number_format((float) $this->payment->amount, 2)." ETB was confirmed. Receipt {$this->payment->receipt_number}.",
            'payment_id' => $this->payment->id,
        ];
    }
}
