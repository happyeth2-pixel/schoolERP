<?php

namespace App\Notifications;

use App\Models\Announcement;
use App\Support\Localization\NotificationLocale;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** In-app only, like every notification in this system. Sent to the announcement's audience the moment it becomes
 * visible -- right away for one published today, or by the announcements:notify-due job on a scheduled one's day. */
class AnnouncementPublished extends Notification
{
    use Queueable;

    public function __construct(
        public Announcement $announcement,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $locale = NotificationLocale::for($notifiable);

        return [
            'title' => __('notifications.announcement_published.title', [], $locale),
            'body' => __('notifications.announcement_published.body', ['title' => $this->announcement->title], $locale),
            'announcement_id' => $this->announcement->id,
        ];
    }
}
