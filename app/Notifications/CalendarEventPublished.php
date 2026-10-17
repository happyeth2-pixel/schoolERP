<?php

namespace App\Notifications;

use App\Models\CalendarEvent;
use App\Support\Localization\NotificationLocale;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** In-app only, like every notification in this system. Sent to the event's audience once, the moment it is
 * created -- never repeated, and never a separate "reminder" as the event's date approaches (the brief's own
 * "do not spam" instruction, applied the same way Announcement's one-shot guard already does). */
class CalendarEventPublished extends Notification
{
    use Queueable;

    public function __construct(
        public CalendarEvent $event,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $locale = NotificationLocale::for($notifiable);

        return [
            'title' => __('notifications.calendar_event_published.title', [], $locale),
            'body' => __('notifications.calendar_event_published.body', ['title' => $this->event->title], $locale),
            'calendar_event_id' => $this->event->id,
        ];
    }
}
