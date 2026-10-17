<?php

namespace App\Services;

use App\Models\AccountInvitation;
use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\AccountInvitationNotification;
use App\Support\SchoolCalendar;

/**
 * The single way a new account is invited to set its password (staff and
 * guardians alike). Issues the link, emails it when a real mail service is
 * configured, records who invited whom, and flashes the link once to the
 * person who created the account -- so it can be handed over in person or by
 * WhatsApp/SMS when email isn't available.
 */
class AccountOnboarding
{
    public static function invite(User $user, ?User $by): array
    {
        $issued = AccountInvitation::issueFor($user, $by);

        $emailed = false;
        if (! in_array(config('mail.default'), ['log', 'array'], true)) {
            try {
                $user->notify(new AccountInvitationNotification($issued['url'], $issued['expires_at'], $by?->name));
                $emailed = true;
            } catch (\Throwable $e) {
                report($e);
            }
        }

        if ($by) {
            AuditLog::record($by, 'account.invited', $user, null, [
                'expires_at' => $issued['expires_at']->toDateTimeString(),
                'emailed' => $emailed,
            ]);
        }

        $summary = [
            'name' => $user->name,
            'email' => $user->email,
            'url' => $issued['url'],
            'expires_at' => SchoolCalendar::dateTime($issued['expires_at'], $user),
            'emailed' => $emailed,
        ];

        session()->flash('invitation', $summary);

        return $summary;
    }
}
