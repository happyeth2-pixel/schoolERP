<?php

namespace App\Notifications;

use App\Models\ParentMessage;
use App\Support\Localization\NotificationLocale;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * In-app only, like every other notification in this app. Deliberately carries no part of the message
 * body -- only who sent it and which student it's about -- so the notification itself can never leak what
 * was actually said; the recipient reads the real content on the message thread, where access is checked
 * again (StudentPolicy::viewMessages).
 */
class NewParentMessage extends Notification
{
    use Queueable;

    public function __construct(
        public ParentMessage $message,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $locale = NotificationLocale::for($notifiable);

        return [
            'title' => __('notifications.new_parent_message.title', [], $locale),
            'body' => __('notifications.new_parent_message.body', [
                'sender' => $this->message->sender->name,
                'student' => $this->message->student->first_name,
            ], $locale),
            'student_id' => $this->message->student_id,
        ];
    }
}
