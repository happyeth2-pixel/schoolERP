<?php

namespace App\Notifications;

use App\Models\Assignment;
use App\Models\Student;
use App\Support\Localization\NotificationLocale;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** In-app only, to the student's guardians, sent exactly once the morning before an assignment's
 * due date (the brief's own "assignment deadline approaching" example, under "do not spam" --
 * `due_soon_notified_at` guards this so a missed/retried cron run never repeats it). */
class AssignmentDueSoon extends Notification
{
    use Queueable;

    public function __construct(
        public Assignment $assignment,
        public ?Student $student,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $locale = NotificationLocale::for($notifiable);

        return [
            'title' => __('notifications.assignment_due_soon.title', [], $locale),
            'body' => __('notifications.assignment_due_soon.body', [
                'subject' => $this->assignment->subject->name,
                'title' => $this->assignment->title,
            ], $locale),
            'assignment_id' => $this->assignment->id,
            'student_id' => $this->student?->id,
        ];
    }
}
