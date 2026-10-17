<?php

namespace App\Notifications;

use App\Models\PurchaseRequest;
use App\Support\Localization\NotificationLocale;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** In-app only. Sent to the requester once every item on their request has actually arrived -- the closing
 * event of the whole request -> order -> receipt chain, so the person who asked for it knows it's here. */
class PurchaseRequestFulfilled extends Notification
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
            'title' => __('notifications.purchase_request_fulfilled.title', [], $locale),
            'body' => __('notifications.purchase_request_fulfilled.body', ['number' => $this->purchaseRequest->number], $locale),
            'purchase_request_id' => $this->purchaseRequest->id,
        ];
    }
}
