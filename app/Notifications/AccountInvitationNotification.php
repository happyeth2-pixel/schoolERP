<?php

namespace App\Notifications;

use App\Support\Localization\NotificationLocale;
use App\Support\SchoolCalendar;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

class AccountInvitationNotification extends Notification
{
    public function __construct(private string $url, private Carbon $expiresAt, private ?string $invitedBy = null)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $school = $notifiable->school?->name ?? config('app.name');
        $locale = NotificationLocale::for($notifiable);

        $createdLine = $this->invitedBy
            ? __('notifications.account_invitation.line_created', ['school' => $school, 'invited_by' => $this->invitedBy], $locale)
            : __('notifications.account_invitation.line_created_no_inviter', ['school' => $school], $locale);

        return (new MailMessage)
            ->subject(__('notifications.account_invitation.subject', ['school' => $school], $locale))
            ->greeting(__('notifications.account_invitation.greeting', ['name' => $notifiable->name], $locale))
            ->line($createdLine)
            ->action(__('notifications.account_invitation.action', [], $locale), $this->url)
            ->line(__('notifications.account_invitation.line_expires', [
                'expires' => SchoolCalendar::dateTime($this->expiresAt, $notifiable->school),
            ], $locale))
            ->line(__('notifications.account_invitation.line_ignore', [], $locale));
    }
}
