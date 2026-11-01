import { ChevronLeftIcon, ChevronRightIcon } from '@heroicons/react/24/outline';
import { useTranslation } from 'react-i18next';

// A plain, mobile-friendly pager for a server-paginated list: a "Showing A-B of C" line, Previous/Next, and a
// short run of page numbers around the current one (collapsed to just Previous/Next + "Page X of Y" on narrow
// screens, since a row of a dozen number buttons does not fit a phone). `meta` is the shape every paginated
// controller in this app returns: { current_page, last_page, per_page, total, from, to }. `onPageChange(page)`
// is left to the caller, since each page preserves its own search/filter query-string state differently.

function pageNumbers(current, last) {
    if (last <= 1) return [];
    const span = 1; // how many neighbours on each side of the current page
    const pages = new Set([1, last, current]);
    for (let d = 1; d <= span; d++) {
        if (current - d >= 1) pages.add(current - d);
        if (current + d <= last) pages.add(current + d);
    }
    const sorted = [...pages].sort((a, b) => a - b);
    const out = [];
    let prev = null;
    for (const p of sorted) {
        if (prev !== null && p - prev > 1) out.push('…');
        out.push(p);
        prev = p;
    }
    return out;
}

export default function Pagination({ meta, onPageChange }) {
    const { t } = useTranslation();
    if (!meta || meta.total === 0) return null;

    const { current_page: current, last_page: last, from, to, total } = meta;

    return (
        <nav className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between" aria-label={t('common.page_of', { page: current, total: last })} data-testid="pagination">
            <p className="text-base text-stone-700 dark:text-stone-300" data-testid="pagination-range">
                {total === 1 ? t('common.showing_range_one') : t('common.showing_range', { from, to, total: total.toLocaleString() })}
            </p>

            {last > 1 && (
                <div className="flex items-center justify-center gap-1.5">
                    <button
                        type="button"
                        onClick={() => onPageChange(current - 1)}
                        disabled={current <= 1}
                        aria-label={t('common.previous')}
                        data-testid="pagination-prev"
                        className="flex h-11 min-w-[44px] items-center justify-center gap-1 rounded-lg border-2 border-stone-300 px-3 text-base font-medium text-stone-700 hover:bg-stone-50 disabled:cursor-not-allowed disabled:opacity-40 dark:border-stone-600 dark:text-stone-200 dark:hover:bg-stone-700"
                    >
                        <ChevronLeftIcon className="h-5 w-5" aria-hidden="true" />
                        <span className="hidden sm:inline">{t('common.previous')}</span>
                    </button>

                    <span className="flex items-center gap-1" data-testid="pagination-numbers">
                        {pageNumbers(current, last).map((p, i) => p === '…' ? (
                            <span key={`ellipsis-${i}`} className="px-1 text-stone-500 dark:text-stone-400" aria-hidden="true">…</span>
                        ) : (
                            <button
                                key={p}
                                type="button"
                                onClick={() => onPageChange(p)}
                                aria-current={p === current ? 'page' : undefined}
                                className={`hidden h-11 min-w-[44px] items-center justify-center rounded-lg px-3 text-base font-medium sm:flex ${p === current ? 'bg-brand-600 text-white' : 'text-stone-700 hover:bg-stone-100 dark:text-stone-200 dark:hover:bg-stone-700'}`}
                            >
                                {p}
                            </button>
                        ))}
                        <span className="text-base text-stone-700 sm:hidden dark:text-stone-300" data-testid="pagination-mobile-label">
                            {t('common.page_of', { page: current, total: last })}
                        </span>
                    </span>

                    <button
                        type="button"
                        onClick={() => onPageChange(current + 1)}
                        disabled={current >= last}
                        aria-label={t('common.next')}
                        data-testid="pagination-next"
                        className="flex h-11 min-w-[44px] items-center justify-center gap-1 rounded-lg border-2 border-stone-300 px-3 text-base font-medium text-stone-700 hover:bg-stone-50 disabled:cursor-not-allowed disabled:opacity-40 dark:border-stone-600 dark:text-stone-200 dark:hover:bg-stone-700"
                    >
                        <span className="hidden sm:inline">{t('common.next')}</span>
                        <ChevronRightIcon className="h-5 w-5" aria-hidden="true" />
                    </button>
                </div>
            )}
        </nav>
    );
}
