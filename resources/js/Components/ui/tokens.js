// The few visual decisions the whole UX system shares, in one place, so "large controls, strong
// selected states, one meaning per colour" cannot drift apart between components. See UX_GUIDELINES.md.

/** Minimum comfortable touch target. Every primary control in the system is at least this tall. */
export const TAP = 'min-h-[56px]';

/** Keyboard focus that is clearly visible (and only for keyboard users, so taps stay clean). */
export const FOCUS =
    'focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-gray-800';

/**
 * One meaning per tone, used by alerts, badges and callouts alike:
 *   success = it worked / it is fine     warning = needs attention, nothing is broken
 *   danger  = failed / destructive       info    = neutral news or "what happens next"
 *   neutral = no state to flag
 * Colour is never the only carrier of meaning: alerts and badges always add an icon and words.
 */
export const TONE = {
    success: {
        box: 'border-emerald-300 bg-emerald-50 text-emerald-950 dark:border-emerald-700 dark:bg-emerald-900/25 dark:text-emerald-50',
        badge: 'bg-emerald-100 text-emerald-900 dark:bg-emerald-900/40 dark:text-emerald-100',
        icon: 'text-emerald-600 dark:text-emerald-400',
    },
    warning: {
        box: 'border-amber-300 bg-amber-50 text-amber-950 dark:border-amber-700 dark:bg-amber-900/25 dark:text-amber-50',
        badge: 'bg-amber-100 text-amber-900 dark:bg-amber-900/40 dark:text-amber-100',
        icon: 'text-amber-600 dark:text-amber-400',
    },
    danger: {
        box: 'border-red-300 bg-red-50 text-red-950 dark:border-red-700 dark:bg-red-900/25 dark:text-red-50',
        badge: 'bg-red-100 text-red-900 dark:bg-red-900/40 dark:text-red-100',
        icon: 'text-red-600 dark:text-red-400',
    },
    info: {
        box: 'border-sky-300 bg-sky-50 text-sky-950 dark:border-sky-700 dark:bg-sky-900/25 dark:text-sky-50',
        badge: 'bg-sky-100 text-sky-900 dark:bg-sky-900/40 dark:text-sky-100',
        icon: 'text-sky-600 dark:text-sky-400',
    },
    neutral: {
        box: 'border-gray-300 bg-gray-50 text-gray-900 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100',
        badge: 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-100',
        icon: 'text-gray-500 dark:text-gray-400',
    },
};

/** Width of the content column on a page. */
export const PAGE_WIDTH = {
    narrow: 'max-w-xl',
    default: 'max-w-3xl',
    wide: 'max-w-5xl',
    full: 'max-w-7xl',
};
