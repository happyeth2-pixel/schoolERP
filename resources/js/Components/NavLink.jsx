import { Link } from '@inertiajs/react';

export default function NavLink({
    active = false,
    className = '',
    children,
    ...props
}) {
    return (
        <Link
            {...props}
            className={
                'inline-flex items-center border-b-2 px-1 pt-1 text-sm font-medium leading-5 transition duration-150 ease-in-out focus:outline-none ' +
                (active
                    ? 'border-brand-400 text-stone-900 focus:border-brand-700 dark:border-brand-600 dark:text-stone-100'
                    : 'border-transparent text-stone-500 hover:border-stone-300 hover:text-stone-700 focus:border-stone-300 focus:text-stone-700 dark:text-stone-400 dark:hover:border-stone-700 dark:hover:text-stone-300 dark:focus:border-stone-700 dark:focus:text-stone-300') +
                className
            }
        >
            {children}
        </Link>
    );
}
