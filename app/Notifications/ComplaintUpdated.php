<?php

namespace App\Notifications;

use App\Models\Complaint;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class ComplaintUpdated extends Notification
{
    use Queueable;

    public function __construct(
        public Complaint $complaint,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Complaint update',
            'body' => "Your complaint \"{$this->complaint->subject}\" is now ".str_replace('_', ' ', $this->complaint->status).'.',
            'complaint_id' => $this->complaint->id,
        ];
    }
}
