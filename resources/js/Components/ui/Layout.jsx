import { ArrowLeftIcon } from '@heroicons/react/24/outline';
import { Head, Link } from '@inertiajs/react';
import { FOCUS, PAGE_WIDTH } from './tokens';

// Page skeleton pieces. The point is that every page has the same rhythm -- a plain title, one
// sentence saying what the page is for, the main action where the eye lands -- so a person who has
// learned one screen has learned them all. (AuthenticatedLayout already provides the top bar.)

/** A flat, calm container. Deliberately NOT Card3D: a card that tilts under the pointer is fine on a
 *  dashboard but makes forms and buttons inside it feel like they are moving. */
export function Card({ children, className = '', as: Tag = 'div', ...props }) {
    return (
        <Tag {...props} className={`rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800 sm:p-6 ${className}`}>
            {children}
        </Tag>
    );
}

/** Heading for a block inside a page. Use `as="h3"` inside a Card that already has a heading. */
export function SectionHeader({ title, description, action, as: Heading = 'h2', className = '' }) {
    return (
        <div className={`mb-4 flex flex-wrap items-start justify-between gap-3 ${className}`}>
            <div className="min-w-0">
                <Heading className="text-lg font-semibold text-gray-900 dark:text-gray-100">{title}</Heading>
                {description && <p className="mt-1 text-base text-gray-600 dark:text-gray-400">{description}</p>}
            </div>
            {action}
        </div>
    );
}

/** "← Back to Students": a real, large, worded link -- never a bare arrow. */
export function BackLink({ href, children }) {
    return (
        <Link href={href} className={`-ml-2 inline-flex min-h-[48px] items-center gap-2 rounded-xl px-2 text-base font-medium text-indigo-700 hover:underline dark:text-indigo-300 ${FOCUS}`}>
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
                    <h1 className="text-2xl font-bold text-gray-900 dark:text-gray-100">{title}</h1>
                    {description && <p className="mt-1 max-w-2xl text-base text-gray-600 dark:text-gray-400">{description}</p>}
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
