import { useTranslation } from 'react-i18next';

/** A small spinning ring. Decorative: the words next to it carry the meaning. */
export function Spinner({ className = 'h-5 w-5' }) {
    return (
        <svg className={`animate-spin ${className}`} viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <circle cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" className="opacity-25" />
            <path d="M4 12a8 8 0 018-8" stroke="currentColor" strokeWidth="4" strokeLinecap="round" className="opacity-90" />
        </svg>
    );
}

/** "Something is happening" for a region that is waiting on data. Always says so in words. */
export function LoadingState({ label, className = '' }) {
    const { t } = useTranslation();

    return (
        <div role="status" className={`flex flex-col items-center justify-center gap-3 py-10 text-gray-600 dark:text-gray-300 ${className}`}>
            <Spinner className="h-8 w-8 text-indigo-600 dark:text-indigo-400" />
            <p className="text-base">{label ?? t('common.loading')}</p>
        </div>
    );
}
