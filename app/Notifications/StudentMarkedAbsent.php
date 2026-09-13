<?php

namespace App\Notifications;

use App\Models\Student;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * In-app only — no SMS/email channel per this project's scope decision.
 * The bell icon in the header is the entire delivery mechanism for now.
 */
class StudentMarkedAbsent extends Notification
{
    use Queueable;

    public function __construct(
        public Student $student,
        public Carbon $date,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Absence recorded',
            'body' => "{$this->student->first_name} was marked absent on {$this->date->format('M j, Y')}.",
            'student_id' => $this->student->id,
        ];
    }
}
