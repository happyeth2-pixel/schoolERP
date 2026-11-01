import { ArrowLeftIcon } from '@heroicons/react/24/outline';
import { Head, Link } from '@inertiajs/react';
import { FOCUS, PAGE_WIDTH } from './tokens';

// Page skeleton pieces. The point is that every page has the same rhythm -- a plain title, one
// sentence saying what the page is for, the main action where the eye lands -- so a person who has
// learned one screen has learned them all. (AuthenticatedLayout already provides the top bar.)

/** A flat, calm container -- a border, not a shadow, is what separates it from the page. Deliberately
 *  NOT Card3D: a card that tilts under the pointer is fine on a dashboard but makes forms and buttons
 *  inside it feel like they are moving. */
export function Card({ children, className = '', as: Tag = 'div', ...props }) {
    return (
        <Tag {...props} className={`rounded-xl border border-stone-200 bg-white p-5 dark:border-stone-700 dark:bg-stone-800 sm:p-6 ${className}`}>
            {children}
        </Tag>
    );
}

/** Heading for a block inside a page. Use `as="h3"` inside a Card that already has a heading. */
export function SectionHeader({ title, description, action, as: Heading = 'h2', className = '' }) {
    return (
        <div className={`mb-4 flex flex-wrap items-start justify-between gap-3 ${className}`}>
            <div className="min-w-0">
                <Heading className="text-lg font-semibold text-stone-900 dark:text-stone-100">{title}</Heading>
                {description && <p className="mt-1 text-base text-stone-600 dark:text-stone-400">{description}</p>}
            </div>
            {action}
        </div>
    );
}

/**
 * "← Back to Students": a real, large, worded link -- never a bare arrow.
 * `big`: a full-width, filled button instead of a text link -- for a parent's own pages, where there is
 * no sidebar at all to fall back on, so the way back has to be as obvious as every other button on the
 * screen, not a smaller link above the title.
 */
export function BackLink({ href, children, big = false }) {
    if (big) {
        return (
            <Link
                href={href}
                className={`mb-4 flex min-h-[56px] w-full items-center justify-center gap-2 rounded-xl border-2 border-stone-300 bg-white px-4 text-lg font-bold text-stone-900 hover:border-brand-500 dark:border-stone-600 dark:bg-stone-800 dark:text-stone-100 dark:hover:border-brand-400 ${FOCUS}`}
            >
                <ArrowLeftIcon className="h-6 w-6" aria-hidden="true" />
                {children}
            </Link>
        );
    }

    return (
        <Link href={href} className={`-ml-2 inline-flex min-h-[48px] items-center gap-2 rounded-lg px-2 text-base font-medium text-brand-700 hover:underline dark:text-brand-300 ${FOCUS}`}>
            <ArrowLeftIcon className="h-5 w-5" aria-hidden="true" />
            {children}
        </Link>
    );
}

/** Title + one-sentence purpose + the main action(s), at the top of a page body. */
export function PageHeader({ title, description, back, actions }) {
    return (
        <div className="mb-6">
            {back && <div className="mb-1">{back}</div>}
            <div className="flex flex-wrap items-start justify-between gap-4">
                <div className="min-w-0">
                    <h1 className="text-2xl font-bold tracking-tight text-stone-900 dark:text-stone-100">{title}</h1>
                    {description && <p className="mt-1 max-w-2xl text-base text-stone-600 dark:text-stone-400">{description}</p>}
                </div>
                {actions && <div className="flex flex-wrap items-center gap-3">{actions}</div>}
            </div>
        </div>
    );
}

/** The body wrapper every page repeats by hand today: browser-tab title, width, padding, header. */
export function Page({ title, description, back, actions, width = 'default', children }) {
    return (
        <>
            <Head title={title} />
            <div className="py-6 sm:py-8">
                <div className={`mx-auto px-4 sm:px-6 lg:px-8 ${PAGE_WIDTH[width] ?? PAGE_WIDTH.default}`}>
                    <PageHeader title={title} description={description} back={back} actions={actions} />
                    <div className="space-y-6">{children}</div>
                </div>
            </div>
        </>
    );
}
