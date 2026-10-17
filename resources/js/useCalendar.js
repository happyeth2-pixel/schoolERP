import { formatDate, todayISO } from '@/calendar';
import { usePage } from '@inertiajs/react';

/** The school's calendar preference + the signed-in person's language, with a ready-made formatter. */
export function useCalendar() {
    const { auth, locale } = usePage().props;
    const preference = auth?.school?.calendar_preference ?? 'both';

    return {
        preference,
        locale: locale ?? 'en',
        format: (iso) => formatDate(iso, preference, locale ?? 'en'),
        todayISO,
    };
}
