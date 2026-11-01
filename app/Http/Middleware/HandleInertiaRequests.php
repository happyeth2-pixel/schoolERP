<?php

namespace App\Http\Middleware;

use App\Models\AcademicYear;
use App\Support\NotificationPresenter;
use App\Support\SchoolCalendar;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            // SetLocale (runs earlier in the web middleware stack, see bootstrap/app.php)
            // already worked out the same value for the backend's own locale -- reusing it here
            // keeps the frontend's i18next language and the backend's validation/notification
            // language from ever disagreeing.
            'locale' => app()->getLocale(),
            // Kept current on every Inertia visit (unlike the one-time inline script in
            // app.blade.php, which only reflects whoever was signed in at the last FULL page
            // load -- Inertia's own SPA-style navigation after login never re-renders that). See
            // resources/js/monitoring.js, which re-applies this to Sentry's user context on every navigation.
            'monitoringUserPseudonym' => $user ? AttachMonitoringContext::pseudonym($user->id) : null,
            'auth' => [
                'user' => $user,
                'role' => $user?->getRoleNames()->first(),
                'permissions' => $user?->getAllPermissions()->pluck('name'),
                'school' => $user?->school,
                'branch' => $user?->branch,
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'warning' => fn () => $request->session()->get('warning'),
                'invitation' => fn () => $request->session()->get('invitation'),
                'credentials' => fn () => \App\Support\TemporaryPassword::readHandOver($request->session()->get('credentials')),
            ],
            'notifications' => $user ? [
                'unreadCount' => $user->unreadNotifications()->count(),
                'recent' => $user->notifications()->latest()->limit(5)->get()->map(fn ($n) => NotificationPresenter::present($n)),
            ] : null,
            // A parent's own children, with their class -- shown in the sidebar on every page (not just
            // the dashboard), so "which grade is my child in" never requires going home first to check.
            'parentChildren' => $user && $user->hasRole('parent')
                ? $user->children()->with('currentEnrollment.section.grade')->orderBy('first_name')->get()
                    ->map(fn ($c) => [
                        'id' => $c->id,
                        'name' => $c->first_name,
                        'class' => $c->currentEnrollment?->section
                            ? "{$c->currentEnrollment->section->grade->name} {$c->currentEnrollment->section->name}"
                            : null,
                    ])
                : null,
            // One centralized "what day is it" for the whole app -- dashboards, attendance, finance and
            // anything else that shows "today" all read this, instead of each page (or the browser's own
            // clock, which was the bug: two dashboard greetings used `new Date().getHours()`, so a device
            // set to a different timezone than the school's showed the wrong time-of-day greeting) computing
            // it separately. `hour` is the business-timezone hour-of-day (0-23), for a "Good
            // morning/afternoon/evening" greeting; `formatted` already respects the school's own calendar
            // preference (Ethiopian/Gregorian/both) via the same SchoolCalendar used everywhere else.
            'today' => $user ? [
                'iso' => SchoolCalendar::today()->toDateString(),
                'weekday' => SchoolCalendar::today()->isoWeekday(),
                // NOT derived from SchoolCalendar::today() -- that deliberately zeroes the time of day
                // (it's a date-only helper). The hour needs "right now", just in the business timezone
                // rather than the server's or the browser's.
                'hour' => (int) \Illuminate\Support\Carbon::now(SchoolCalendar::timezone())->format('G'),
                'formatted' => SchoolCalendar::date(SchoolCalendar::today(), $user),
                'academic_year' => $user->school_id
                    ? AcademicYear::where('school_id', $user->school_id)->where('is_current', true)->value('name')
                    : null,
            ] : null,
        ];
    }
}
