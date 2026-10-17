import { useCalendar } from '@/useCalendar';

/** A stored (Gregorian) date shown in the school's calendar. */
export default function DateText({ value, fallback = '—' }) {
    const { format } = useCalendar();
    return value ? format(value) : fallback;
}
