import { CheckCircleIcon, ClockIcon, MinusCircleIcon, XCircleIcon } from '@heroicons/react/24/solid';
import { useTranslation } from 'react-i18next';

const STATUS = {
    complete: {
        icon: CheckCircleIcon,
        labelKey: 'attendance.progress_complete',
        classes: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
    },
    partial: {
        icon: ClockIcon,
        labelKey: 'attendance.progress_partial',
        classes: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
    },
    none: {
        icon: XCircleIcon,
        labelKey: 'attendance.progress_none',
        classes: 'bg-rose-100 text-rose-700 dark:bg-rose-900/40 dark:text-rose-300',
    },
    empty: {
        icon: MinusCircleIcon,
        labelKey: 'attendance.progress_empty',
        classes: 'bg-stone-100 text-stone-500 dark:bg-stone-700 dark:text-stone-400',
    },
};

export default function AttendanceStatusBadge({ status, className = '' }) {
    const { t } = useTranslation();
    const s = STATUS[status] ?? STATUS.empty;
    const Icon = s.icon;

    return (
        <span className={`inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-sm font-semibold ${s.classes} ${className}`}>
            <Icon className="h-4 w-4" />
            {t(s.labelKey)}
        </span>
    );
}
