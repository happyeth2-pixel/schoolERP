<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes the signed-in user's chosen display language (or, if they never chose one, their
 * school's first enabled language) the backend's locale too -- not just the frontend's.
 * Without this, `App::setLocale()` never runs, so validation errors, notification bodies
 * (mail + database, see app/Notifications) and any other server-rendered text stay in
 * English regardless of what a user picked in Settings. See LOCALIZATION.md.
 *
 * The set of locales this actually has translations for is fixed at the same four the
 * School Settings language picker offers (en/am/om/ti, see SchoolSettingsController) --
 * anything else (a stray value, a stale locale from before a school disabled a language)
 * silently falls back to English rather than throwing or rendering blank strings.
 */
class SetLocale
{
    public const SUPPORTED = ['en', 'am', 'om', 'ti'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $enabledLanguages = $user?->school?->enabled_languages ?? ['en'];
        $locale = $user?->locale ?? $enabledLanguages[0] ?? 'en';

        if (! in_array($locale, self::SUPPORTED, true)) {
            $locale = 'en';
        }

        app()->setLocale($locale);

        return $next($request);
    }
}
