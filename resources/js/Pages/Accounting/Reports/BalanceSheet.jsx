import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

const money = (n) => Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

export default function BalanceSheet({ as_of, assets, liabilities, equity, total_assets, total_liabilities, total_equity, balanced }) {
    const { t } = useTranslation();

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">{t('reports.balance_sheet_title')}</h2>}>
            <Head title={t('reports.balance_sheet_title')} />

            <div className="py-8">
                <div className="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
                    <div className="mb-6 flex items-end gap-3">
                        <div>
                            <label className="block text-xs font-medium uppercase text-gray-500">{t('reports.as_of_date')}</label>
                            <input
                                type="date"
                                className="mt-1 rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
                                value={as_of}
                                onChange={(e) => router.get(route('reports.balance-sheet'), { as_of: e.target.value }, { preserveState: true })}
                            />
                        </div>
                        {!balanced && (
                            <p className="text-sm font-semibold text-red-600 dark:text-red-400" data-testid="bs-unbalanced-warning">{t('reports.balance_sheet_unbalanced')}</p>
                        )}
                    </div>

                    <div className="overflow-x-auto rounded-lg bg-white shadow-sm dark:bg-gray-800">
                        <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead className="bg-gray-50 dark:bg-gray-900/40">
                                <tr>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">{t('reports.account')}</th>
                                    <th className="px-4 py-3 text-right text-xs font-medium uppercase text-gray-500">{t('reports.amount')}</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
                                <tr><td colSpan={2} className="px-4 py-2 text-xs font-semibold uppercase text-gray-500">{t('reports.assets')}</td></tr>
                                {assets.map((r) => (
                                    <tr key={r.code ?? r.name} data-testid="bs-asset-row">
                                        <td className="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">{r.code} {r.name}</td>
                                        <td className="px-4 py-3 text-right text-sm text-gray-700 dark:text-gray-300">{money(r.amount)}</td>
                                    </tr>
                                ))}
                                <tr className="font-semibold">
                                    <td className="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">{t('reports.total_assets')}</td>
                                    <td className="px-4 py-3 text-right text-sm text-gray-900 dark:text-gray-100" data-testid="bs-total-assets">{money(total_assets)}</td>
                                </tr>

                                <tr><td colSpan={2} className="px-4 py-2 text-xs font-semibold uppercase text-gray-500">{t('reports.liabilities')}</td></tr>
                                {liabilities.map((r) => (
                                    <tr key={r.code ?? r.name} data-testid="bs-liability-row">
                                        <td className="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">{r.code} {r.name}</td>
                                        <td className="px-4 py-3 text-right text-sm text-gray-700 dark:text-gray-300">{money(r.amount)}</td>
                                    </tr>
                                ))}
                                <tr className="font-semibold">
                                    <td className="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">{t('reports.total_liabilities')}</td>
                                    <td className="px-4 py-3 text-right text-sm text-gray-900 dark:text-gray-100" data-testid="bs-total-liabilities">{money(total_liabilities)}</td>
                                </tr>

                                <tr><td colSpan={2} className="px-4 py-2 text-xs font-semibold uppercase text-gray-500">{t('reports.equity')}</td></tr>
                                {equity.map((r, i) => (
                                    <tr key={r.code ?? `${r.name}-${i}`} data-testid="bs-equity-row">
                                        <td className="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">{r.code ? `${r.code} ${r.name}` : r.name}</td>
                                        <td className="px-4 py-3 text-right text-sm text-gray-700 dark:text-gray-300">{money(r.amount)}</td>
                                    </tr>
                                ))}
                                <tr className="font-semibold">
                                    <td className="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">{t('reports.total_equity')}</td>
                                    <td className="px-4 py-3 text-right text-sm text-gray-900 dark:text-gray-100" data-testid="bs-total-equity">{money(total_equity)}</td>
                                </tr>

                                {assets.length === 0 && liabilities.length === 0 && equity.length === 0 && (
                                    <tr><td colSpan={2} className="px-4 py-6 text-center text-sm text-gray-400">{t('reports.no_activity')}</td></tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
