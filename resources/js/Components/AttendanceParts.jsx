import { CheckCircleIcon, ClockIcon, DocumentCheckIcon, ExclamationTriangleIcon, FaceFrownIcon, XCircleIcon } from '@heroicons/react/24/outline';
import { useTranslation } from 'react-i18next';

// The five statuses the application already supports (present, absent, late, excused, sick) -- nothing new.
// Each is an icon + a word + a colour: colour alone never carries the meaning. `on` is the filled, selected look
// (700-level fills so white text keeps readable contrast); `chip` is the quiet version used in summaries.
export const STATUSES = [
    { value: 'present', icon: CheckCircleIcon, on: 'border-emerald-700 bg-emerald-700 text-white', chip: 'bg-emerald-100 text-emerald-900 dark:bg-emerald-900/40 dark:text-emerald-100' },
    { value: 'absent', icon: XCircleIcon, on: 'border-red-700 bg-red-700 text-white', chip: 'bg-red-100 text-red-900 dark:bg-red-900/40 dark:text-red-100' },
    { value: 'late', icon: ClockIcon, on: 'border-amber-700 bg-amber-700 text-white', chip: 'bg-amber-100 text-amber-900 dark:bg-amber-900/40 dark:text-amber-100' },
    { value: 'excused', icon: DocumentCheckIcon, on: 'border-blue-700 bg-blue-700 text-white', chip: 'bg-blue-100 text-blue-900 dark:bg-blue-900/40 dark:text-blue-100' },
    { value: 'sick', icon: FaceFrownIcon, on: 'border-purple-700 bg-purple-700 text-white', chip: 'bg-purple-100 text-purple-900 dark:bg-purple-900/40 dark:text-purple-100' },
];

export const statusLabelKey = (value) => `attendance.status_${value}`;

/** "23 of 30 students marked", a bar, what is missing, and (optionally) how the marks are split. */
export function MarkProgress({ marked, total, counts, short = false }) {
    const { t } = useTranslation();
    const done = total > 0 && marked >= total;
    const pct = total > 0 ? Math.round((marked / total) * 100) : 0;

    return (
        <div>
            <p className="text-xl font-bold text-gray-900 dark:text-gray-100" data-testid="progress-text">{short ? t('attendance.progress_short', { marked, count: total }) : t('attendance.progress', { marked, count: total })}</p>
            <div
                role="progressbar"
                aria-label={t('attendance.progress', { marked, count: total })}
                aria-valuemin={0}
                aria-valuemax={total}
                aria-valuenow={marked}
                className="mt-2 h-3 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700"
            >
                <div className={`h-3 rounded-full transition-all ${done ? 'bg-emerald-600' : 'bg-indigo-600'}`} style={{ width: `${pct}%` }} />
            </div>
            {done ? (
                <p className="mt-2 flex items-center gap-1.5 text-base font-semibold text-emerald-800 dark:text-emerald-300" data-testid="progress-state">
                    <CheckCircleIcon className="h-5 w-5" aria-hidden="true" /> {t('attendance.all_marked')}
                </p>
            ) : (
                <p className="mt-2 flex items-center gap-1.5 text-base font-semibold text-amber-800 dark:text-amber-300" data-testid="progress-state">
                    <ExclamationTriangleIcon className="h-5 w-5" aria-hidden="true" /> {t('attendance.unmarked_count', { n: total - marked })}
                </p>
            )}
            {counts && (
                <ul className="mt-3 flex flex-wrap gap-2" aria-label={t('attendance.breakdown')}>
                    {STATUSES.filter((s) => counts[s.value] > 0).map((s) => (
                        <li key={s.value} className={`inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-sm font-semibold ${s.chip}`}>
                            <s.icon className="h-4 w-4" aria-hidden="true" /> {t(statusLabelKey(s.value))}: {counts[s.value]}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
