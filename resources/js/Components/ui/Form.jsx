import { ExclamationCircleIcon } from '@heroicons/react/24/solid';
import { Children, cloneElement, forwardRef, isValidElement, useId } from 'react';
import { useTranslation } from 'react-i18next';
import { FOCUS } from './tokens';

// Form building blocks: a label you can read, a hint that says what to type, the error right beside
// the field in plain words, and inputs tall enough to hit with a thumb.

const CONTROL =
    'block min-h-[56px] w-full rounded-xl border-gray-400 bg-white text-base text-gray-900 shadow-sm placeholder:text-gray-400 focus:border-indigo-600 focus:ring-2 focus:ring-indigo-600 dark:border-gray-500 dark:bg-gray-900 dark:text-gray-100';
const INVALID = 'border-red-600 focus:border-red-600 focus:ring-red-600';

/**
 * Label + control + hint + error, wired together for screen readers (aria-describedby/invalid).
 * `children` is the control: either an element (it receives id/aria props) or a function
 * ({ id, ...aria }) => element for anything unusual.
 */
export function Field({ id, label, hint, error, optional = false, children, className = '' }) {
    const { t } = useTranslation();
    const auto = useId();
    const fieldId = id ?? auto;
    const hintId = hint ? `${fieldId}-hint` : undefined;
    const errorId = error ? `${fieldId}-error` : undefined;
    const aria = {
        id: fieldId,
        'aria-describedby': [hintId, errorId].filter(Boolean).join(' ') || undefined,
        'aria-invalid': error ? true : undefined,
        invalid: error ? true : undefined,
    };

    let control;
    if (typeof children === 'function') {
        control = children(aria);
    } else {
        const only = Children.only(children);
        // `invalid` is for our own controls; a raw DOM element would warn about an unknown attribute.
        const { invalid, ...domAria } = aria;
        control = isValidElement(only) ? cloneElement(only, { ...(typeof only.type === 'string' ? domAria : aria), ...only.props }) : only;
    }

    return (
        <div className={className}>
            <label htmlFor={fieldId} className="block text-base font-medium text-gray-900 dark:text-gray-100">
                {label}
                {optional && <span className="ml-2 text-sm font-normal text-gray-500 dark:text-gray-400">({t('common.optional')})</span>}
            </label>
            {hint && <p id={hintId} className="mt-0.5 text-sm text-gray-600 dark:text-gray-400">{hint}</p>}
            <div className="mt-2">{control}</div>
            <FieldError id={errorId} message={error} />
        </div>
    );
}

/** The error message that belongs to one field: beside it, with an icon, in plain words. */
export function FieldError({ message, id }) {
    if (!message) return null;

    return (
        <p id={id} role="alert" className="mt-2 flex items-start gap-2 text-sm font-medium text-red-700 dark:text-red-400">
            <ExclamationCircleIcon className="mt-0.5 h-5 w-5 shrink-0" aria-hidden="true" />
            <span>{message}</span>
        </p>
    );
}

export const Input = forwardRef(function Input({ invalid, className = '', type = 'text', ...props }, ref) {
    return <input ref={ref} type={type} {...props} className={`${CONTROL} ${invalid ? INVALID : ''} ${className}`} />;
});

export const TextArea = forwardRef(function TextArea({ invalid, className = '', rows = 4, ...props }, ref) {
    return <textarea ref={ref} rows={rows} {...props} className={`${CONTROL} min-h-[112px] ${invalid ? INVALID : ''} ${className}`} />;
});

/**
 * A native <select>, made large. Use this only for LONG lists (a section out of forty, a student out of
 * hundreds). For up to about six options, ChoiceGroup shows every option at once and is far easier.
 */
export const Select = forwardRef(function Select({ invalid, className = '', children, ...props }, ref) {
    return (
        <select ref={ref} {...props} className={`${CONTROL} pr-10 ${invalid ? INVALID : ''} ${className}`}>
            {children}
        </select>
    );
});

/** A whole row you can tap to tick, with the meaning written out -- for permission lists and consents. */
export function CheckRow({ checked, onChange, label, hint, disabled = false, id }) {
    const auto = useId();
    const rowId = id ?? auto;

    return (
        <label
            htmlFor={rowId}
            className={`flex min-h-[56px] items-start gap-4 rounded-xl border-2 px-4 py-3 ${FOCUS} ${
                checked ? 'border-indigo-600 bg-indigo-50 dark:border-indigo-400 dark:bg-indigo-900/30' : 'border-gray-300 bg-white dark:border-gray-600 dark:bg-gray-800'
            } ${disabled ? 'cursor-not-allowed opacity-50' : 'cursor-pointer hover:border-indigo-400'}`}
        >
            <input
                id={rowId}
                type="checkbox"
                checked={checked}
                disabled={disabled}
                onChange={(e) => onChange(e.target.checked)}
                className="mt-1 h-6 w-6 shrink-0 rounded-md border-gray-500 text-indigo-600 focus:ring-2 focus:ring-indigo-600"
            />
            <span>
                <span className="block text-base font-semibold text-gray-900 dark:text-gray-100">{label}</span>
                {hint && <span className="mt-0.5 block text-sm text-gray-600 dark:text-gray-400">{hint}</span>}
            </span>
        </label>
    );
}
