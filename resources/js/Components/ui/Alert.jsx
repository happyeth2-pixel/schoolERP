import { CheckCircleIcon, ExclamationTriangleIcon, InformationCircleIcon, XCircleIcon } from '@heroicons/react/24/solid';
import { XMarkIcon } from '@heroicons/react/24/outline';
import { usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { FOCUS, TONE } from './tokens';

const ICON = { success: CheckCircleIcon, warning: ExclamationTriangleIcon, danger: XCircleIcon, info: InformationCircleIcon, neutral: InformationCircleIcon };

/**
 * A message the person must not miss. Icon + words + colour (never colour alone). Errors and warnings
 * are announced immediately to screen readers (role="alert"); successes and notes politely.
 * Put a `title` on anything important and say what to do next in the body or `action`.
 */
export default function Alert({ tone = 'info', title, children, action, onDismiss, className = '' }) {
    const { t } = useTranslation();
    const Icon = ICON[tone] ?? ICON.info;
    const urgent = tone === 'danger' || tone === 'warning';

    return (
        <div role={urgent ? 'alert' : 'status'} className={`flex items-start gap-3 rounded-xl border-2 p-4 ${TONE[tone]?.box ?? TONE.info.box} ${className}`}>
            <Icon className={`mt-0.5 h-6 w-6 shrink-0 ${TONE[tone]?.icon ?? ''}`} aria-hidden="true" />
            <div className="min-w-0 flex-1 text-base">
                <span className="sr-only">{t(`ui.tone_${tone}`)}: </span>
                {title && <p className="font-semibold">{title}</p>}
                {children && <div className={title ? 'mt-1' : ''}>{children}</div>}
                {action && <div className="mt-3">{action}</div>}
            </div>
            {onDismiss && (
                <button type="button" onClick={onDismiss} aria-label={t('ui.dismiss')} title={t('ui.dismiss')} className={`-m-1 flex h-11 w-11 shrink-0 items-center justify-center rounded-lg hover:bg-black/5 dark:hover:bg-white/10 ${FOCUS}`}>
                    <XMarkIcon className="h-5 w-5" aria-hidden="true" />
                </button>
            )}
        </div>
    );
}

/**
 * Replaces the green/amber banner that 24 pages each copy by hand. Drop it once near the top of a page:
 * it shows the server's `flash.success` / `flash.warning`, plus any non-field errors named in `errorKeys`
 * (e.g. errorKeys={['payment', 'locked']}). Field-level errors stay beside their fields (ui/Form).
 * A success is dismissible; an error is not, so it cannot be waved away before it is read.
 */
export function FlashMessages({ errorKeys = [], skip = [] }) {
    const { flash, errors } = usePage().props;
    const [dismissed, setDismissed] = useState({});

    const items = [
        !skip.includes('success') && flash?.success && { key: `s:${flash.success}`, tone: 'success', text: flash.success },
        !skip.includes('warning') && flash?.warning && { key: `w:${flash.warning}`, tone: 'warning', text: flash.warning },
        ...errorKeys.filter((k) => errors?.[k]).map((k) => ({ key: `e:${k}:${errors[k]}`, tone: 'danger', text: errors[k], sticky: true })),
    ].filter(Boolean);

    // a new message must show even if an earlier identical one was dismissed
    const signature = items.map((i) => i.key).join('|');
    useEffect(() => setDismissed({}), [signature]);

    const visible = items.filter((i) => !dismissed[i.key]);
    if (visible.length === 0) return null;

    return (
        <div className="space-y-3">
            {visible.map((i) => (
                <Alert key={i.key} tone={i.tone} onDismiss={i.sticky ? undefined : () => setDismissed((d) => ({ ...d, [i.key]: true }))}>
                    {i.text}
                </Alert>
            ))}
        </div>
    );
}
