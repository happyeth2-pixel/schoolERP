<?php

namespace App\Notifications;

use App\Models\Student;
use App\Support\Localization\GenderedText;
use App\Support\Localization\NotificationLocale;
use App\Support\SchoolCalendar;
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
        $locale = NotificationLocale::for($notifiable);
        $key = GenderedText::key('notifications.student_marked_absent', $this->student->gender);

        return [
            'title' => __("{$key}.title", [], $locale),
            'body' => __("{$key}.body", [
                'student' => $this->student->first_name,
                'date' => SchoolCalendar::date($this->date, $this->student->school),
            ], $locale),
            'student_id' => $this->student->id,
        ];
    }
}
