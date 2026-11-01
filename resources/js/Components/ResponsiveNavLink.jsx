import { Link } from '@inertiajs/react';

export default function ResponsiveNavLink({
    active = false,
    className = '',
    children,
    ...props
}) {
    return (
        <Link
            {...props}
            className={`flex w-full items-start border-l-4 py-2 pe-4 ps-3 ${
                active
                    ? 'border-brand-400 bg-brand-50 text-brand-700 focus:border-brand-700 focus:bg-brand-100 focus:text-brand-800 dark:border-brand-600 dark:bg-brand-900/50 dark:text-brand-300 dark:focus:border-brand-300 dark:focus:bg-brand-900 dark:focus:text-brand-200'
                    : 'border-transparent text-stone-600 hover:border-stone-300 hover:bg-stone-50 hover:text-stone-800 focus:border-stone-300 focus:bg-stone-50 focus:text-stone-800 dark:text-stone-400 dark:hover:border-stone-600 dark:hover:bg-stone-700 dark:hover:text-stone-200 dark:focus:border-stone-600 dark:focus:bg-stone-700 dark:focus:text-stone-200'
            } text-base font-medium transition duration-150 ease-in-out focus:outline-none ${className}`}
        >
            {children}
        </Link>
    );
}
