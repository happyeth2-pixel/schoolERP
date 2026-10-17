<?php

namespace App\Support\Localization;

use App\Http\Middleware\SetLocale;

/**
 * A notification's toArray()/toMail() runs in the CURRENT request -- the person doing the
 * action (a staff member marking attendance, verifying a payment) -- not a queued job run
 * later for the recipient. Using app()->getLocale() here would show the notification in the
 * ACTOR's language, not the RECIPIENT's, which is wrong the moment they differ (an Amharic-
 * speaking staff member marks a student absent; the guardian reads Afaan Oromo). This is
 * why every Notification class in app/Notifications resolves the recipient's own locale
 * explicitly with this helper instead of relying on the ambient app locale.
 */
class NotificationLocale
{
    public static function for(object $notifiable): string
    {
        $locale = $notifiable->locale ?? $notifiable->school?->enabled_languages[0] ?? 'en';

        return in_array($locale, SetLocale::SUPPORTED, true) ? $locale : 'en';
    }
}
