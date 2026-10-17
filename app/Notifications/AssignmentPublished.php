<?php

namespace App\Notifications;

use App\Models\Assignment;
use App\Models\Student;
use App\Support\Localization\NotificationLocale;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** In-app only, to the student's guardians, the moment an assignment is published -- academic
 * expansion, Phase 8. Never sent for a draft, and never repeated once published (the brief's own
 * "do not spam" instruction). One instance per student, so each guardian's own portal link lands on
 * the right child even though the assignment itself covers a whole section. */
class AssignmentPublished extends Notification
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
            'title' => __('notifications.assignment_published.title', [], $locale),
            'body' => __('notifications.assignment_published.body', [
                'subject' => $this->assignment->subject->name,
                'title' => $this->assignment->title,
            ], $locale),
            'assignment_id' => $this->assignment->id,
            'student_id' => $this->student?->id,
        ];
    }
}
