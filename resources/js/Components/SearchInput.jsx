import { MagnifyingGlassIcon } from '@heroicons/react/24/outline';
import { useTranslation } from 'react-i18next';

export default function SearchInput({ value, onChange, placeholder }) {
    const { t } = useTranslation();

    return (
        <div className="relative">
            <MagnifyingGlassIcon className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-stone-400" />
            <input
                type="text"
                value={value}
                onChange={(e) => onChange(e.target.value)}
                placeholder={placeholder ?? t('common.search_placeholder')}
                className="w-full rounded-md border-stone-300 py-2 pl-9 pr-3 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500 dark:border-stone-700 dark:bg-stone-900 dark:text-stone-100"
            />
        </div>
    );
}
