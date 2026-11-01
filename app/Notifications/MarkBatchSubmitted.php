<?php

namespace App\Notifications;

use App\Models\MarkBatch;
use App\Support\Localization\NotificationLocale;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** In-app only, to whoever holds approve-results in the batch's own branch (plus org-wide holders --
 * owner/principal), the moment a teacher submits a batch of results for approval. */
class MarkBatchSubmitted extends Notification
{
    use Queueable;

    public function __construct(
        public MarkBatch $batch,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $locale = NotificationLocale::for($notifiable);

        return [
            'title' => __('notifications.mark_batch_submitted.title', [], $locale),
            'body' => __('notifications.mark_batch_submitted.body', [
                'subject' => $this->batch->subject->name,
                'section' => "{$this->batch->section->grade->name} {$this->batch->section->name}",
                'exam' => $this->batch->exam->name,
                'teacher' => $this->batch->teacher->name,
            ], $locale),
            'mark_batch_id' => $this->batch->id,
        ];
    }
}
