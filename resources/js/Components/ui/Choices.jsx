import { CheckIcon } from '@heroicons/react/24/solid';
import { useId } from 'react';
import { FOCUS } from './tokens';

// "Dropdown alternatives". A dropdown hides its options behind a tiny tap and a tiny list; a person
// who is not sure what is in it has to open it to find out. These show every option at once.
//
//   ChoiceGroup layout="cards"      2-6 options that need a sentence of explanation each
//                                   (how did you pay? new parent / existing parent / later)
//   ChoiceGroup layout="segmented"  2-3 short options side by side (Male | Female, Yes | No)
//   Select (ui/Form)                a long list only (forty sections, hundreds of students)

/** One big, obviously-tappable option with a strong selected state (border + tint + tick + filled icon). */
export function ChoiceCard({ selected, onSelect, icon: Icon, title, badge, hint, disabled = false, role = 'radio' }) {
    return (
        <button
            type="button"
            role={role}
            aria-checked={selected}
            disabled={disabled}
            onClick={onSelect}
            className={`flex min-h-[64px] w-full items-center gap-4 rounded-xl border-2 px-4 py-3 text-left transition ${FOCUS} ${
                selected
                    ? 'border-indigo-600 bg-indigo-50 dark:border-indigo-400 dark:bg-indigo-900/30'
                    : 'border-gray-300 bg-white hover:border-indigo-400 dark:border-gray-600 dark:bg-gray-800 dark:hover:border-indigo-500'
            } ${disabled ? 'cursor-not-allowed opacity-50' : ''}`}
        >
            {Icon && (
                <span className={`flex h-11 w-11 shrink-0 items-center justify-center rounded-lg ${selected ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200'}`}>
                    <Icon className="h-6 w-6" aria-hidden="true" />
                </span>
            )}
            <span className="flex-1">
                <span className="flex flex-wrap items-center gap-2">
                    <span className="block text-base font-semibold text-gray-900 dark:text-gray-100">{title}</span>
                    {badge}
                </span>
                {hint && <span className="mt-0.5 block text-sm text-gray-600 dark:text-gray-400">{hint}</span>}
            </span>
            <span
                aria-hidden="true"
                className={`flex h-7 w-7 shrink-0 items-center justify-center rounded-full ${selected ? 'bg-indigo-600 text-white' : 'border-2 border-gray-400 dark:border-gray-500'}`}
            >
                {selected && <CheckIcon className="h-4 w-4" />}
            </span>
        </button>
    );
}

/**
 * options: [{ value, label, hint?, icon?, disabled? }]. Nothing is preselected unless `value` says so:
 * for questions about money or records the person should make the choice, not accept a default.
 */
export function ChoiceGroup({ value, onChange, options, label, heading, layout = 'cards' }) {
    // `heading` shows the question as visible text above the choices (and names the group for screen readers);
    // `label` alone is only the spoken name, for groups whose question is already the page or step title.
    const headingId = useId();
    const groupProps = heading ? { 'aria-labelledby': headingId } : { 'aria-label': label };
    const title = heading ? <p id={headingId} className="mb-2 text-lg font-semibold text-gray-900 dark:text-gray-100">{heading}</p> : null;

    if (layout === 'segmented') {
        return (
            <div>
                {title}
                <div role="radiogroup" {...groupProps} className="flex gap-2">
                {options.map((o) => {
                    const selected = value === o.value;
                    return (
                        <button
                            key={o.value}
                            type="button"
                            role="radio"
                            aria-checked={selected}
                            disabled={o.disabled}
                            onClick={() => onChange(o.value)}
                            className={`flex min-h-[56px] flex-1 items-center justify-center gap-2 rounded-xl border-2 px-4 text-base font-semibold transition ${FOCUS} ${
                                selected
                                    ? 'border-indigo-600 bg-indigo-600 text-white'
                                    : 'border-gray-300 bg-white text-gray-900 hover:border-indigo-400 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100'
                            } ${o.disabled ? 'cursor-not-allowed opacity-50' : ''}`}
                        >
                            {selected && <CheckIcon className="h-5 w-5" aria-hidden="true" />}
                            {o.label}
                        </button>
                    );
                })}
                </div>
            </div>
        );
    }

    return (
        <div>
            {title}
            <div role="radiogroup" {...groupProps} className="space-y-3">
                {options.map((o) => (
                    <ChoiceCard key={o.value} selected={value === o.value} onSelect={() => onChange(o.value)} icon={o.icon} title={o.label} hint={o.hint} disabled={o.disabled} />
                ))}
            </div>
        </div>
    );
}
