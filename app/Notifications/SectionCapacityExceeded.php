<?php

namespace App\Notifications;

use App\Models\Section;
use App\Support\Localization\NotificationLocale;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Sent to a branch's own principal and the school's owner(s) the moment someone with
 * override-section-capacity (a branch admin or the owner) places a student into a section that was already
 * full -- see SectionCapacity::grant(). In-app only, like every other notification in this app.
 */
class SectionCapacityExceeded extends Notification
{
    use Queueable;

    public function __construct(
        public Section $section,
        public int $occupiedAfter,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $locale = NotificationLocale::for($notifiable);
        $name = trim(($this->section->grade?->name ?? '').' '.$this->section->name);

        return [
            'title' => __('notifications.section_capacity_exceeded.title', [], $locale),
            'body' => __('notifications.section_capacity_exceeded.body', [
                'section' => $name,
                'occupied' => $this->occupiedAfter,
                'capacity' => $this->section->capacity,
            ], $locale),
            'section_id' => $this->section->id,
        ];
    }
}
