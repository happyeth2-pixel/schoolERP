<?php

namespace App\Notifications;

use App\Models\PurchaseRequest;
use App\Support\Localization\NotificationLocale;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** In-app only. Sent to the requester the moment their purchase request is approved. */
class PurchaseRequestApproved extends Notification
{
    use Queueable;

    public function __construct(
        public PurchaseRequest $purchaseRequest,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $locale = NotificationLocale::for($notifiable);

        return [
            'title' => __('notifications.purchase_request_approved.title', [], $locale),
            'body' => __('notifications.purchase_request_approved.body', ['number' => $this->purchaseRequest->number], $locale),
            'purchase_request_id' => $this->purchaseRequest->id,
        ];
    }
}
