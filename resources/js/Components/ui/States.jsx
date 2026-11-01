import { CheckCircleIcon } from '@heroicons/react/24/solid';
import { ChevronDownIcon, InboxIcon } from '@heroicons/react/24/outline';
import { FOCUS } from './tokens';

/**
 * "Nothing here yet" that says WHY and WHAT TO DO, instead of a grey line of small print. Give it an
 * `action` (a Button) whenever the person can fix the emptiness right there.
 */
export function EmptyState({ icon: Icon = InboxIcon, title, children, action, className = '' }) {
    return (
        <div className={`flex flex-col items-center px-4 py-10 text-center ${className}`}>
            <span className="flex h-16 w-16 items-center justify-center rounded-full bg-stone-100 dark:bg-stone-700">
                <Icon className="h-8 w-8 text-stone-500 dark:text-stone-300" aria-hidden="true" />
            </span>
            <p className="mt-4 text-lg font-semibold text-stone-900 dark:text-stone-100">{title}</p>
            {children && <p className="mt-1 max-w-md text-base text-stone-600 dark:text-stone-400">{children}</p>}
            {action && <div className="mt-5">{action}</div>}
        </div>
    );
}

/**
 * The confirmation that an important thing WORKED, shown on the page where it happened (not only as a
 * banner that disappears). Says what was done and what happens next; `actions` offers the obvious
 * next step(s).
 */
export function SuccessPanel({ title, children, details, actions, className = '' }) {
    return (
        <div role="status" className={`rounded-xl border-2 border-emerald-300 bg-emerald-50 p-6 text-center dark:border-emerald-700 dark:bg-emerald-900/25 ${className}`}>
            <CheckCircleIcon className="mx-auto h-14 w-14 text-emerald-600 dark:text-emerald-400" aria-hidden="true" />
            <h3 className="mt-3 text-xl font-semibold text-emerald-950 dark:text-emerald-50">{title}</h3>
            {children && <p className="mt-2 text-base text-emerald-900 dark:text-emerald-100">{children}</p>}
            {details && <div className="mt-4 text-left">{details}</div>}
            {actions && <div className="mt-5 flex flex-col items-center justify-center gap-3 sm:flex-row">{actions}</div>}
        </div>
    );
}

/**
 * Progressive disclosure: the less-used part of a page, one clear tap away instead of always in the way.
 * A large, worded summary line (never a bare arrow); the content stays in the page (find-in-page works,
 * nothing is removed). `tone="danger"` styles the rarely-wanted, hard-to-undo corners (withdraw, delete).
 */
export function Disclosure({ title, description, defaultOpen = false, tone = 'neutral', children, className = '', ...props }) {
    const danger = tone === 'danger';

    return (
        <details
            open={defaultOpen}
            {...props}
            className={`group rounded-2xl border-2 bg-white dark:bg-stone-800 ${danger ? 'border-red-200 dark:border-red-900' : 'border-stone-200 dark:border-stone-700'} ${className}`}
        >
            <summary className={`flex min-h-[56px] cursor-pointer list-none items-center justify-between gap-3 rounded-2xl px-5 py-3 ${FOCUS}`}>
                <span className="min-w-0">
                    <span className={`block text-lg font-semibold ${danger ? 'text-red-800 dark:text-red-300' : 'text-stone-900 dark:text-stone-100'}`}>{title}</span>
                    {description && <span className="block text-sm text-stone-600 dark:text-stone-400">{description}</span>}
                </span>
                <ChevronDownIcon className="h-6 w-6 shrink-0 text-stone-500 transition group-open:rotate-180" aria-hidden="true" />
            </summary>
            <div className="border-t border-stone-200 p-5 dark:border-stone-700">{children}</div>
        </details>
    );
}
