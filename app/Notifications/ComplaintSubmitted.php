<?php

namespace App\Notifications;

use App\Models\Complaint;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class ComplaintSubmitted extends Notification
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
            'title' => 'New complaint',
            'body' => "\"{$this->complaint->subject}\" from {$this->complaint->submittedBy->name}",
            'complaint_id' => $this->complaint->id,
        ];
    }
}
