<?php

namespace App\Notifications;

use App\Models\Payment;
use App\Support\Localization\NotificationLocale;
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
        $locale = NotificationLocale::for($notifiable);

        return [
            'title' => __('notifications.payment_verified.title', [], $locale),
            'body' => __('notifications.payment_verified.body', [
                'amount' => number_format((float) $this->payment->amount, 2),
                'receipt' => $this->payment->receipt_number,
            ], $locale),
            'payment_id' => $this->payment->id,
        ];
    }
}
