import { BackLink } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

const money = (n) => Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

function ReconciliationCard({ title, data, matchLabel, mismatchLabel, t }) {
    if (!data) {
        return null;
    }

    return (
        <div className="overflow-hidden rounded-lg bg-white shadow-sm dark:bg-stone-800" data-testid="reconciliation-card">
            <div className="border-b border-stone-200 px-4 py-3 dark:border-stone-700">
                <h3 className="text-base font-semibold text-stone-900 dark:text-stone-100">{title}</h3>
            </div>
            <dl className="divide-y divide-stone-200 px-4 dark:divide-stone-700">
                <div className="flex items-center justify-between py-3">
                    <dt className="text-sm text-stone-600 dark:text-stone-400">{t('reports.reconciliation_ledger_balance')}</dt>
                    <dd className="text-sm font-semibold text-stone-900 dark:text-stone-100">{money(data.ledger)}</dd>
                </div>
                <div className="flex items-center justify-between py-3">
                    <dt className="text-sm text-stone-600 dark:text-stone-400">{t('reports.reconciliation_system_total')}</dt>
                    <dd className="text-sm font-semibold text-stone-900 dark:text-stone-100">{money(data.system)}</dd>
                </div>
            </dl>
            <div className={`px-4 py-3 text-sm font-semibold ${data.matches ? 'bg-green-50 text-green-700 dark:bg-green-900/30 dark:text-green-400' : 'bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-400'}`} data-testid={data.matches ? 'reconciliation-match' : 'reconciliation-mismatch'}>
                {data.matches ? matchLabel : `${mismatchLabel} (${money(data.diff)})`}
            </div>
        </div>
    );
}

export default function Reconciliation({ accounting_installed, ar, ap, checked_at }) {
    const { t } = useTranslation();

    if (!accounting_installed) {
        return (
            <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">{t('reports.reconciliation_title')}</h2>}>
                <Head title={t('reports.reconciliation_title')} />
                <div className="py-8">
                    <div className="mx-auto max-w-2xl px-4 sm:px-6 lg:px-8">
                        <BackLink href={route('reports.index')}>{t('common.back')}</BackLink>
                        <p className="text-sm text-stone-600 dark:text-stone-400">{t('reports.reconciliation_not_installed')}</p>
                    </div>
                </div>
            </AuthenticatedLayout>
        );
    }

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">{t('reports.reconciliation_title')}</h2>}>
            <Head title={t('reports.reconciliation_title')} />

            <div className="py-8">
                <div className="mx-auto max-w-2xl px-4 sm:px-6 lg:px-8">
                    <BackLink href={route('reports.index')}>{t('common.back')}</BackLink>
                    <div className="mb-6 flex items-center justify-between">
                        <p className="text-sm text-stone-500" data-testid="reconciliation-checked-at">{t('reports.reconciliation_checked_at', { time: checked_at })}</p>
                        <button
                            type="button"
                            className="rounded-md bg-brand-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-brand-500"
                            onClick={() => router.reload()}
                        >
                            {t('reports.reconciliation_recheck')}
                        </button>
                    </div>

                    <div className="space-y-6">
                        <ReconciliationCard
                            title={t('reports.reconciliation_ar_title')}
                            data={ar}
                            matchLabel={t('reports.reconciliation_match')}
                            mismatchLabel={t('reports.reconciliation_mismatch')}
                            t={t}
                        />
                        <ReconciliationCard
                            title={t('reports.reconciliation_ap_title')}
                            data={ap}
                            matchLabel={t('reports.reconciliation_match')}
                            mismatchLabel={t('reports.reconciliation_mismatch')}
                            t={t}
                        />
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
