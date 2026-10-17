<?php

namespace App\Notifications;

use App\Models\Payslip;
use App\Support\Localization\NotificationLocale;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** In-app only, to the employee's OWN linked account once their payslip for a run is finalized -- same
 * "the decision/record belongs to the person it affects" rule as LeaveRequestReviewed/
 * PerformanceReviewFinalized. */
class PayslipReady extends Notification
{
    use Queueable;

    public function __construct(
        public Payslip $payslip,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $locale = NotificationLocale::for($notifiable);

        return [
            'title' => __('notifications.payslip_ready.title', [], $locale),
            'body' => __('notifications.payslip_ready.body', [
                'period' => $this->payslip->payrollRun->label(),
            ], $locale),
            'payslip_id' => $this->payslip->id,
            'employee_id' => $this->payslip->employee_id,
        ];
    }
}
