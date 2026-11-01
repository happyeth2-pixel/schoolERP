import { useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { Spinner } from './Loading';
import { FOCUS } from './tokens';

// One button for the whole app. Compared with the older PrimaryButton/SecondaryButton/DangerButton
// (small, upper-case, 12px), this one is large by default, sentence case, and refuses to be
// clicked twice while it is working -- the two things that matter most to a hesitant user.
//
//   variant  primary   the ONE main action on a screen (there should be exactly one)
//            secondary the safe alternative (Back, Cancel)
//            danger    something that cannot easily be undone; label MUST name what is lost
//            success   the final "send it" action of a wizard
//            quiet     a low-key action inside a row or card
//   size     lg = 56px (default; anything a person must find and press)
//            md = 44px (dense staff tables where a 56px button would not fit)

const VARIANTS = {
    primary: 'border-transparent bg-brand-700 text-white hover:bg-brand-800 active:bg-brand-900',
    secondary:
        'border-stone-300 bg-white text-stone-900 hover:bg-stone-50 active:bg-stone-100 dark:border-stone-600 dark:bg-stone-800 dark:text-stone-100 dark:hover:bg-stone-700',
    danger: 'border-transparent bg-red-700 text-white hover:bg-red-800 active:bg-red-900',
    success: 'border-transparent bg-emerald-700 text-white hover:bg-emerald-800 active:bg-emerald-900', // 700, not 600: white on 600 is only 3.8:1
    quiet: 'border-transparent bg-transparent text-brand-700 hover:bg-brand-50 dark:text-brand-300 dark:hover:bg-brand-900/30',
};

const SIZES = {
    lg: 'min-h-[56px] px-6 text-base',
    md: 'min-h-[44px] px-4 text-sm',
};

// A button that says only this tells the person nothing about what will happen. In development the
// console says so; the rule is "name the outcome": "Save Attendance", "Create invoices",
// "Reject Payment", "Delete Student".
const VAGUE_LABELS = new Set(['submit', 'confirm', 'ok', 'okay', 'yes', 'done', 'delete', 'remove', 'reject', 'approve', 'go', 'send']);

export default function Button({
    variant = 'primary',
    size = 'lg',
    busy = false,
    busyLabel,
    icon: Icon,
    fullWidth = false,
    type = 'button',
    as: Tag = 'button',
    disabled = false,
    className = '',
    children,
    ...props
}) {
    const { t } = useTranslation();

    useEffect(() => {
        if (import.meta.env.DEV && typeof children === 'string' && VAGUE_LABELS.has(children.trim().toLowerCase())) {
            // eslint-disable-next-line no-console
            console.warn(`[ui/Button] "${children}" does not say what will happen. Name the outcome, e.g. "Save Attendance" or "Delete Student".`);
        }
    }, [children]);

    const isNativeButton = Tag === 'button';
    const inactive = disabled || busy;

    return (
        <Tag
            {...props}
            {...(isNativeButton ? { type, disabled: inactive } : {})}
            aria-busy={busy || undefined}
            aria-disabled={!isNativeButton && inactive ? true : undefined}
            className={`inline-flex items-center justify-center gap-2 rounded-lg border-2 font-semibold tracking-[-0.01em] transition ${FOCUS} ${SIZES[size]} ${VARIANTS[variant]} ${
                fullWidth ? 'w-full' : ''
            } ${inactive ? 'cursor-not-allowed opacity-50' : ''} ${className}`}
        >
            {busy ? <Spinner /> : Icon && <Icon className="h-5 w-5 shrink-0" aria-hidden="true" />}
            <span>{busy ? busyLabel ?? t('common.working') : children}</span>
        </Tag>
    );
}

/**
 * An icon-only control. Icon-only buttons are easy to misread, so `label` is REQUIRED: it becomes the
 * accessible name and the hover/long-press title. 48px on a phone, 40px on a desktop.
 */
export function IconButton({ icon: Icon, label, badge, className = '', ...props }) {
    if (import.meta.env.DEV && !label) {
        // eslint-disable-next-line no-console
        console.error('[ui/IconButton] needs a `label` so people (and screen readers) know what it does.');
    }

    return (
        <button
            type="button"
            aria-label={label}
            title={label}
            {...props}
            className={`relative inline-flex h-12 w-12 items-center justify-center rounded-lg text-stone-600 hover:bg-stone-100 dark:text-stone-300 dark:hover:bg-stone-800 md:h-10 md:w-10 ${FOCUS} ${className}`}
        >
            <Icon className="h-6 w-6" aria-hidden="true" />
            {badge ? (
                <span className="absolute right-0.5 top-0.5 flex h-5 min-w-[1.25rem] items-center justify-center rounded-full bg-red-600 px-1 text-[11px] font-semibold text-white">
                    {badge}
                </span>
            ) : null}
        </button>
    );
}
