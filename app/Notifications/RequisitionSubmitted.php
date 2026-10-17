<?php

namespace App\Notifications;

use App\Models\Requisition;
use App\Support\Localization\NotificationLocale;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** In-app only. Sent to whoever can approve it (requisition-approve, in the requisition's branch scope)
 * the moment a requester submits -- action IS required from them, which is the whole point of a notification. */
class RequisitionSubmitted extends Notification
{
    use Queueable;

    public function __construct(
        public Requisition $requisition,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $locale = NotificationLocale::for($notifiable);

        return [
            'title' => __('notifications.requisition_submitted.title', [], $locale),
            'body' => __('notifications.requisition_submitted.body', ['number' => $this->requisition->number, 'requester' => $this->requisition->requester->name], $locale),
            'requisition_id' => $this->requisition->id,
        ];
    }
}
