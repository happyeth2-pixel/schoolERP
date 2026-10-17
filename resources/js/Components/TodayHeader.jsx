import { usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

/**
 * "Today • Thursday, 15 October 2026" (+ the current academic year, when known) -- one reusable place for
 * this, instead of each dashboard computing its own date. Reads the `today` prop shared on every Inertia
 * request (see HandleInertiaRequests), which is itself computed server-side from the school's configured
 * business timezone, never the browser's clock, and already respects the school's own calendar preference
 * (Ethiopian/Gregorian/both) via the same SchoolCalendar used everywhere else in the app.
 *
 * Visually secondary by design (small, muted) -- it is context, not the main thing on the page.
 */
export default function TodayHeader({ className = '' }) {
    const { t } = useTranslation();
    const { today } = usePage().props;

    if (!today) return null;

    const weekday = t(`timetable.day_${today.weekday}`);
    const line = today.academic_year
        ? t('home.today_line_with_year', { weekday, date: today.formatted, year: today.academic_year })
        : t('home.today_line', { weekday, date: today.formatted });

    return (
        <p className={`text-sm font-medium text-gray-500 dark:text-gray-400 ${className}`}>
            <time dateTime={today.iso}>{line}</time>
        </p>
    );
}
