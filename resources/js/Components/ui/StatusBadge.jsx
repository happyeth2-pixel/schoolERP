import { CheckCircleIcon, ClockIcon, ExclamationTriangleIcon, InformationCircleIcon, MinusCircleIcon, XCircleIcon } from '@heroicons/react/24/solid';
import { useTranslation } from 'react-i18next';
import { TONE } from './tokens';

// Seven pages each keep their own little colour table for statuses. This is the one table. A status
// is shown as icon + words + colour so it is understood without relying on colour alone.

const ICON = { success: CheckCircleIcon, warning: ExclamationTriangleIcon, danger: XCircleIcon, info: InformationCircleIcon, neutral: MinusCircleIcon };

/** status value (as the backend spells it) -> tone. Add new statuses here, and to `status.*` in lang/*.json. */
export const STATUS_TONE = {
    // payments
    pending: 'warning', verified: 'success', rejected: 'danger',
    // mark sheets
    not_started: 'neutral', draft: 'neutral', submitted: 'info', approved: 'info', published: 'success', sent_back: 'warning',
    // complaints
    open: 'warning', in_progress: 'info', resolved: 'success',
    // invoices
    unpaid: 'danger', partially_paid: 'warning', paid: 'success', waived: 'neutral',
    // people / accounts
    active: 'success', inactive: 'neutral',
    // attendance (labels reuse the attendance.* translations)
    present: 'success', absent: 'danger', late: 'warning', excused: 'info', sick: 'info',
};

const LABEL_KEY = {
    present: 'attendance.status_present', absent: 'attendance.status_absent', late: 'attendance.status_late',
    excused: 'attendance.status_excused', sick: 'attendance.status_sick',
};

export default function StatusBadge({ status, tone, label, className = '' }) {
    const { t } = useTranslation();
    const resolvedTone = tone ?? STATUS_TONE[status] ?? 'neutral';
    const Icon = status === 'pending' ? ClockIcon : ICON[resolvedTone];

    return (
        <span className={`inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-sm font-semibold ${TONE[resolvedTone].badge} ${className}`}>
            <Icon className="h-4 w-4 shrink-0" aria-hidden="true" />
            {label ?? t(LABEL_KEY[status] ?? `status.${status}`)}
        </span>
    );
}
