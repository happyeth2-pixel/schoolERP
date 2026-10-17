<?php

namespace App\Notifications;

use App\Models\TimetableSlot;
use App\Support\Localization\NotificationLocale;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** In-app only, to the teacher a slot is FOR -- academic expansion, Phase 1. Sent on every create/edit of
 * one of their slots; deliberately NOT sent to guardians/students for every single schedule edit (the
 * brief's own "do not spam" instruction) -- parents see the timetable through the portal itself, Phase 9. */
class TimetableChanged extends Notification
{
    use Queueable;

    public function __construct(
        public TimetableSlot $slot,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $locale = NotificationLocale::for($notifiable);

        return [
            'title' => __('notifications.timetable_changed.title', [], $locale),
            'body' => __('notifications.timetable_changed.body', [
                'subject' => $this->slot->subject->name,
                'section' => $this->slot->section->auditLabel(),
            ], $locale),
            'timetable_slot_id' => $this->slot->id,
        ];
    }
}
