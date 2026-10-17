<?php

namespace App\Notifications;

use App\Models\Requisition;
use App\Support\Localization\NotificationLocale;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** In-app only. Sent to the requester the moment their requisition is rejected, with the reason -- so they
 * know without having to go and check. */
class RequisitionRejected extends Notification
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
            'title' => __('notifications.requisition_rejected.title', [], $locale),
            'body' => __('notifications.requisition_rejected.body', ['number' => $this->requisition->number, 'reason' => $this->requisition->rejection_reason], $locale),
            'requisition_id' => $this->requisition->id,
        ];
    }
}
