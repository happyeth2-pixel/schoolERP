<?php

namespace App\Notifications;

use App\Models\Requisition;
use App\Support\Localization\NotificationLocale;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** In-app only. Sent to the requester (to know it's coming) and to whoever can issue stock for it (to know
 * to prepare it) -- both are told the same fact, once, right when approval happens. */
class RequisitionApproved extends Notification
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
            'title' => __('notifications.requisition_approved.title', [], $locale),
            'body' => __('notifications.requisition_approved.body', ['number' => $this->requisition->number], $locale),
            'requisition_id' => $this->requisition->id,
        ];
    }
}
