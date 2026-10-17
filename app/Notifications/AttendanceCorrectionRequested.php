<?php

namespace App\Notifications;

use App\Models\StaffAttendanceCorrection;
use App\Support\Localization\NotificationLocale;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** In-app only, to whoever holds staff-attendance-manage in the employee's own branch (plus org-wide
 * holders) EXCEPT the person who requested the correction -- they can never review their own request. Says
 * only the employee's name and the date, never the proposed values, since those are sensitive enough to
 * belong only behind the gated corrections review screen. */
class AttendanceCorrectionRequested extends Notification
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

        return [
            'title' => __('notifications.attendance_correction_requested.title', [], $locale),
            'body' => __('notifications.attendance_correction_requested.body', [
                'name' => $attendance->employee->full_name,
                'date' => $attendance->date->toDateString(),
            ], $locale),
            'staff_attendance_correction_id' => $this->correction->id,
            'employee_id' => $attendance->employee_id,
        ];
    }
}
