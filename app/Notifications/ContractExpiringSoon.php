<?php

namespace App\Notifications;

use App\Models\EmploymentContract;
use App\Support\Localization\NotificationLocale;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** In-app only. Sent once per contract (see CheckContractExpiry's `expiry_notified` guard) to whoever holds
 * contract-manage in the employee's own branch (plus org-wide holders), the moment a contract crosses into
 * its notice window -- never repeated daily after that. Deliberately says only the employee's name and the
 * date, never salary or other HR-sensitive detail, since a generic notification is a worse place for that
 * than the employee's own gated profile page. */
class ContractExpiringSoon extends Notification
{
    use Queueable;

    public function __construct(
        public EmploymentContract $contract,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $locale = NotificationLocale::for($notifiable);

        return [
            'title' => __('notifications.contract_expiring_soon.title', [], $locale),
            'body' => __('notifications.contract_expiring_soon.body', [
                'name' => $this->contract->employee->full_name,
                'date' => $this->contract->end_date->toDateString(),
            ], $locale),
            'employee_id' => $this->contract->employee_id,
        ];
    }
}
