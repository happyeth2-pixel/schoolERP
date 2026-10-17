<?php

namespace App\Notifications;

use App\Models\LeaveRequest;
use App\Support\Localization\NotificationLocale;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** In-app only, to whoever holds leave-approve in the employee's own branch (plus org-wide holders) EXCEPT
 * the person who submitted the request -- they can never review their own. Says only the employee's name,
 * leave type, and dates, never the reason (kept behind the gated review screen). */
class LeaveRequestSubmitted extends Notification
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

        return [
            'title' => __('notifications.leave_request_submitted.title', [], $locale),
            'body' => __('notifications.leave_request_submitted.body', [
                'name' => $this->leaveRequest->employee->full_name,
                'type' => $this->leaveRequest->leaveType->name,
                'start' => $this->leaveRequest->start_date->toDateString(),
                'end' => $this->leaveRequest->end_date->toDateString(),
            ], $locale),
            'leave_request_id' => $this->leaveRequest->id,
            'employee_id' => $this->leaveRequest->employee_id,
        ];
    }
}
