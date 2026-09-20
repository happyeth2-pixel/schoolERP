<?php

namespace App\Notifications;

use App\Models\Payment;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class PaymentRejected extends Notification
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
            'title' => 'Payment rejected',
            'body' => 'Your payment of '.number_format((float) $this->payment->amount, 2)." ETB was rejected: {$this->payment->rejection_reason}",
            'payment_id' => $this->payment->id,
        ];
    }
}
