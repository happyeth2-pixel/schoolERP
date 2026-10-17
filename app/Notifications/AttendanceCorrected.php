<?php

namespace App\Notifications;

use App\Models\Student;
use App\Support\Localization\NotificationLocale;
use App\Support\SchoolCalendar;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * Sent to a child's guardians when an attendance mark they were already told about
 * (an absence) is changed to something else, so the earlier alert does not stand
 * uncorrected. In-app only, like the absence alert itself.
 */
class AttendanceCorrected extends Notification
{
    use Queueable;

    public function __construct(
        public Student $student,
        public Carbon $date,
        public string $from,
        public string $to,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $when = SchoolCalendar::date($this->date, $this->student->school);
        $locale = NotificationLocale::for($notifiable);

        return [
            'title' => __('notifications.attendance_corrected.title', [], $locale),
            'body' => __('notifications.attendance_corrected.body', [
                'student' => $this->student->first_name,
                'date' => $when,
                'from' => __("attendance.status.{$this->from}", [], $locale),
                'to' => __("attendance.status.{$this->to}", [], $locale),
            ], $locale),
            'student_id' => $this->student->id,
        ];
    }
}
