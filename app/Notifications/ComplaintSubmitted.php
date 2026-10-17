<?php

namespace App\Notifications;

use App\Models\Complaint;
use App\Support\Localization\NotificationLocale;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class ComplaintSubmitted extends Notification
{
    use Queueable;

    public function __construct(
        public Complaint $complaint,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $locale = NotificationLocale::for($notifiable);

        return [
            'title' => __('notifications.complaint_submitted.title', [], $locale),
            'body' => __('notifications.complaint_submitted.body', [
                'subject' => $this->complaint->subject,
                'name' => $this->complaint->submittedBy->name,
            ], $locale),
            'complaint_id' => $this->complaint->id,
        ];
    }
}
