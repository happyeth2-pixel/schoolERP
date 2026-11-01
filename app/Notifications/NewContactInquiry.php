<?php

namespace App\Notifications;

use App\Models\ContactInquiry;
use App\Support\Localization\NotificationLocale;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** In-app only, same as every other notification in this app -- sent to every owner/branch admin when someone submits the public "Contact Us" form. */
class NewContactInquiry extends Notification
{
    use Queueable;

    public function __construct(public ContactInquiry $inquiry) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $locale = NotificationLocale::for($notifiable);

        return [
            'title' => __('notifications.contact_inquiry_received.title', [], $locale),
            'body' => __('notifications.contact_inquiry_received.body', [
                'name' => $this->inquiry->name,
                'phone' => $this->inquiry->phone,
            ], $locale),
            'inquiry_id' => $this->inquiry->id,
        ];
    }
}
