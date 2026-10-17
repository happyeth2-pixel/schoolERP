<?php

namespace App\Notifications;

use App\Models\LeaveRequest;
use App\Support\Localization\NotificationLocale;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** In-app only, to the employee's OWN linked account (never just whoever happened to submit it -- if HR
 * submitted on an employee's behalf, the decision still belongs to the employee it affects) once someone
 * else has approved or rejected their request. */
class LeaveRequestReviewed extends Notification
{
    use Queueable;

    public function __construct(
        public LeaveRequest $leaveRequest,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $locale = NotificationLocale::for($notifiable);
        $key = $this->leaveRequest->status === 'approved' ? 'approved' : 'rejected';

        return [
            'title' => __("notifications.leave_request_{$key}.title", [], $locale),
            'body' => __("notifications.leave_request_{$key}.body", [
                'type' => $this->leaveRequest->leaveType->name,
                'start' => $this->leaveRequest->start_date->toDateString(),
                'end' => $this->leaveRequest->end_date->toDateString(),
            ], $locale),
            'leave_request_id' => $this->leaveRequest->id,
            'employee_id' => $this->leaveRequest->employee_id,
        ];
    }
}
