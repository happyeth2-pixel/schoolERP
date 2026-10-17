<?php

namespace App\Notifications;

use App\Models\PaymentRefund;
use App\Support\Localization\NotificationLocale;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** Tells a child's guardians that a verified payment was refunded or reversed, and why. */
class PaymentRefunded extends Notification
{
    use Queueable;

    public function __construct(public PaymentRefund $refund) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $r = $this->refund;
        $student = $r->payment->invoice->student;
        $key = $r->kind === 'refund' ? 'refunded' : 'reversed';
        $locale = NotificationLocale::for($notifiable);

        return [
            'title' => __("notifications.payment_refunded.title_{$key}", [], $locale),
            'body' => __("notifications.payment_refunded.body_{$key}", [
                'amount' => number_format((float) $r->amount, 2),
                'student' => $student->first_name,
                'reason' => $r->reason,
            ], $locale),
            'invoice_id' => $r->payment->invoice_id,
        ];
    }
}
