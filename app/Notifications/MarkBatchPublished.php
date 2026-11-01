<?php

namespace App\Notifications;

use App\Models\MarkBatch;
use App\Models\Student;
use App\Support\Localization\NotificationLocale;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** In-app only, to the student's guardians, the moment a result becomes visible to them -- the same
 * "one instance per student" shape AssignmentPublished already uses, so each guardian's own link lands
 * on the right child even though the batch itself covers a whole section. */
class MarkBatchPublished extends Notification
{
    use Queueable;

    public function __construct(
        public MarkBatch $batch,
        public Student $student,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $locale = NotificationLocale::for($notifiable);

        return [
            'title' => __('notifications.mark_batch_published.title', [], $locale),
            'body' => __('notifications.mark_batch_published.body', [
                'subject' => $this->batch->subject->name,
                'exam' => $this->batch->exam->name,
                'student' => $this->student->fullName(),
            ], $locale),
            'mark_batch_id' => $this->batch->id,
            'student_id' => $this->student->id,
        ];
    }
}
