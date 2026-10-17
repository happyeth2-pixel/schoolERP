import { daysInMonth, ethiopianToIso, isoToEthiopian, monthName } from '@/calendar';
import { useCalendar } from '@/useCalendar';
import { useEffect, useMemo, useState } from 'react';

const SELECT = 'rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100';
// `large` (used by the guided flows): the same field with 56px controls, for people who find small selects hard to hit.
const SELECT_LARGE = 'min-h-[56px] rounded-xl border-gray-400 text-base shadow-sm focus:border-indigo-600 focus:ring-2 focus:ring-indigo-600 dark:border-gray-500 dark:bg-gray-900 dark:text-gray-100';

/**
 * A date field that follows the school's calendar preference.
 *
 *  - gregorian: the browser's normal date picker;
 *  - ethiopian / both: day + month + year selects in the Ethiopian calendar
 *    (with the Gregorian equivalent shown underneath when the preference is "both").
 *
 * Whatever the person sees, `value` and `onChange` are ALWAYS the stored form:
 * a Gregorian 'YYYY-MM-DD' string (or '' while incomplete / out of range).
 */
export default function DateInput({ id, value, onChange, min, max, className = '', required = false, large = false }) {
    const { preference, locale, format, todayISO } = useCalendar();
    const control = large ? SELECT_LARGE : SELECT;

    if (preference === 'gregorian') {
        return (
            <input
                id={id}
                type="date"
                min={min}
                max={max}
                required={required}
                value={value ?? ''}
                onChange={(e) => onChange(e.target.value)}
                className={`${control} ${large ? 'w-full' : ''} ${className}`}
            />
        );
    }

    return <EthiopianDateInput {...{ id, value, onChange, min, max, className, preference, locale, format, todayISO, control, large }} />;
}

function EthiopianDateInput({ id, value, onChange, min, max, className, preference, locale, format, todayISO, control, large }) {
    const fromValue = (iso) => {
        const e = iso ? isoToEthiopian(iso) : null;
        return e ? { y: e.year, m: e.month, d: e.day } : { y: '', m: '', d: '' };
    };

    const [parts, setParts] = useState(() => fromValue(value));
    const [rangeError, setRangeError] = useState(false);

    // keep the selects in step when the parent changes the value (e.g. a form reset)
    useEffect(() => {
        if ((value ?? '') !== (ethiopianToIso(Number(parts.y), Number(parts.m), Number(parts.d)) ?? '')) {
            setParts(fromValue(value));
            setRangeError(false);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [value]);

    const thisYear = isoToEthiopian(todayISO()).year;
    const years = useMemo(() => {
        const first = min ? isoToEthiopian(min)?.year ?? thisYear - 110 : thisYear - 110;
        const last = max ? isoToEthiopian(max)?.year ?? thisYear + 15 : thisYear + 15;
        const list = [];
        for (let y = last; y >= first; y--) list.push(y);
        if (parts.y && !list.includes(Number(parts.y))) list.push(Number(parts.y));
        return list;
    }, [min, max, thisYear, parts.y]);

    const monthLength = parts.y && parts.m ? daysInMonth(Number(parts.y), Number(parts.m)) : 30;

    const update = (next) => {
        // a day that doesn't exist in the newly chosen month/year (30 Pagume, 6 Pagume in a non-leap year) is pulled back
        if (next.y && next.m && next.d && Number(next.d) > daysInMonth(Number(next.y), Number(next.m))) {
            next = { ...next, d: daysInMonth(Number(next.y), Number(next.m)) };
        }
        setParts(next);

        if (!(next.y && next.m && next.d)) {
            setRangeError(false);
            onChange('');
            return;
        }

        const iso = ethiopianToIso(Number(next.y), Number(next.m), Number(next.d));
        if (iso && (!min || iso >= min) && (!max || iso <= max)) {
            setRangeError(false);
            onChange(iso);
        } else {
            setRangeError(true);
            onChange('');
        }
    };

    return (
        <div className={className}>
            <div id={id} className={`flex gap-2 ${large ? '' : 'flex-wrap'}`}>
                <select aria-label="Day" className={`${control} ${large ? 'min-w-0 flex-1' : ''}`} value={parts.d} onChange={(e) => update({ ...parts, d: e.target.value })}>
                    <option value="">Day</option>
                    {Array.from({ length: monthLength }, (_, i) => i + 1).map((d) => <option key={d} value={d}>{d}</option>)}
                </select>
                <select aria-label="Month" className={`${control} ${large ? 'min-w-0 flex-[2]' : ''}`} value={parts.m} onChange={(e) => update({ ...parts, m: e.target.value })}>
                    <option value="">Month</option>
                    {Array.from({ length: 13 }, (_, i) => i + 1).map((m) => <option key={m} value={m}>{monthName(m, locale)}</option>)}
                </select>
                <select aria-label="Year" className={`${control} ${large ? 'min-w-0 flex-[1.5]' : ''}`} value={parts.y} onChange={(e) => update({ ...parts, y: e.target.value })}>
                    <option value="">Year</option>
                    {years.map((y) => <option key={y} value={y}>{y}</option>)}
                </select>
            </div>
            {preference === 'both' && value && <p className="mt-1 text-xs text-gray-400">= {format(value).split('(').pop().replace(')', '')}</p>}
            {rangeError && (
                <p className="mt-1 text-xs text-red-600">
                    Choose a date{min ? ` on or after ${format(min)}` : ''}{min && max ? ' and' : ''}{max ? ` on or before ${format(max)}` : ''}.
                </p>
            )}
        </div>
    );
}
