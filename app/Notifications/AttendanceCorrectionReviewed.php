<?php

namespace App\Notifications;

use App\Models\StaffAttendanceCorrection;
use App\Support\Localization\NotificationLocale;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** In-app only, back to whoever requested a correction once someone else has approved or rejected it. */
class AttendanceCorrectionReviewed extends Notification
{
    use Queueable;

    public function __construct(
        public StaffAttendanceCorrection $correction,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $locale = NotificationLocale::for($notifiable);
        $attendance = $this->correction->staffAttendance;
        $key = $this->correction->status === 'approved' ? 'approved' : 'rejected';

        return [
            'title' => __("notifications.attendance_correction_{$key}.title", [], $locale),
            'body' => __("notifications.attendance_correction_{$key}.body", [
                'name' => $attendance->employee->full_name,
                'date' => $attendance->date->toDateString(),
            ], $locale),
            'staff_attendance_correction_id' => $this->correction->id,
            'employee_id' => $attendance->employee_id,
        ];
    }
}
