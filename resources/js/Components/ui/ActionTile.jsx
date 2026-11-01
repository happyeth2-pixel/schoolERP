import { ChevronRightIcon } from '@heroicons/react/24/outline';
import { Link } from '@inertiajs/react';
import { FOCUS, TONE } from './tokens';

/**
 * A big, obvious "do this" tile for a home page: an icon, what it does in plain words, one sentence of
 * explanation, and an optional count ("6 waiting"). The whole tile is the link (>= 112px tall).
 * `href` starting with "#" scrolls within the page; anything else is a normal Inertia visit.
 * badge: { text, tone } -- e.g. { text: '6 waiting', tone: 'warning' }
 */
export default function ActionTile({ icon: Icon, title, description, href, badge, tint, className = '', onClick, ...rest }) {
    const Tag = href.startsWith('#') ? 'a' : Link;

    return (
        <Tag
            href={href}
            onClick={onClick}
            {...rest}
            className={`group relative flex min-h-[112px] items-center gap-4 rounded-xl border-2 border-stone-200 bg-white p-5 transition hover:border-brand-600 active:bg-brand-50 dark:border-stone-700 dark:bg-stone-800 dark:hover:border-brand-400 dark:active:bg-brand-900/30 ${FOCUS} ${className}`}
        >
            <span className={`flex h-14 w-14 shrink-0 items-center justify-center rounded-lg ${tint ?? 'bg-brand-700 text-white'}`}>
                <Icon className="h-8 w-8" aria-hidden="true" />
            </span>
            <span className="min-w-0 flex-1">
                <span className="block text-lg font-semibold leading-snug text-stone-900 dark:text-stone-100">{title}</span>
                {description && <span className="mt-0.5 block text-base leading-snug text-stone-600 dark:text-stone-400">{description}</span>}
                {badge && (
                    <span className={`mt-2 inline-block rounded-full px-3 py-0.5 text-sm font-semibold ${TONE[badge.tone ?? 'info'].badge}`}>{badge.text}</span>
                )}
            </span>
            <ChevronRightIcon className="h-6 w-6 shrink-0 text-stone-400 group-hover:text-brand-600" aria-hidden="true" />
        </Tag>
    );
}
