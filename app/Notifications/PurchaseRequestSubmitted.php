<?php

namespace App\Notifications;

use App\Models\PurchaseRequest;
use App\Support\Localization\NotificationLocale;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** In-app only. Sent to whoever can approve it (purchase-approve, or purchase-approve-large once the
 * request is over the school's configured threshold) the moment a requester submits. */
class PurchaseRequestSubmitted extends Notification
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
            'title' => __('notifications.purchase_request_submitted.title', [], $locale),
            'body' => __('notifications.purchase_request_submitted.body', ['number' => $this->purchaseRequest->number, 'requester' => $this->purchaseRequest->requester->name], $locale),
            'purchase_request_id' => $this->purchaseRequest->id,
        ];
    }
}
