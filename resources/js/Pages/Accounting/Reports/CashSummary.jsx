import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

const money = (n) => Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

export default function CashSummary({ as_of, rows, total }) {
    const { t } = useTranslation();

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">{t('reports.cash_summary_title')}</h2>}>
            <Head title={t('reports.cash_summary_title')} />

            <div className="py-8">
                <div className="mx-auto max-w-2xl px-4 sm:px-6 lg:px-8">
                    <div className="mb-6">
                        <label className="block text-xs font-medium uppercase text-gray-500">{t('reports.as_of_date')}</label>
                        <input
                            type="date"
                            className="mt-1 rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
                            value={as_of}
                            onChange={(e) => router.get(route('reports.cash-summary'), { as_of: e.target.value }, { preserveState: true })}
                        />
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
                                {rows.map((r) => (
                                    <tr key={r.code} data-testid="cash-row">
                                        <td className="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">{r.code} {r.name}</td>
                                        <td className="px-4 py-3 text-right text-sm text-gray-700 dark:text-gray-300">{money(r.balance)}</td>
                                    </tr>
                                ))}
                                {rows.length === 0 && (
                                    <tr><td colSpan={2} className="px-4 py-6 text-center text-sm text-gray-400">{t('reports.no_activity')}</td></tr>
                                )}
                            </tbody>
                            <tfoot>
                                <tr className="border-t-2 border-gray-300 font-bold dark:border-gray-600">
                                    <td className="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">{t('reports.total')}</td>
                                    <td className="px-4 py-3 text-right text-sm text-gray-900 dark:text-gray-100" data-testid="cash-total">{money(total)}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
