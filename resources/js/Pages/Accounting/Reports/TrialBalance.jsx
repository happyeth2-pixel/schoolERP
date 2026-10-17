import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

const money = (n) => Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

export default function TrialBalance({ as_of, rows, total_debit, total_credit }) {
    const { t } = useTranslation();
    const balanced = Math.abs(total_debit - total_credit) < 0.005;

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">{t('reports.trial_balance_title')}</h2>}>
            <Head title={t('reports.trial_balance_title')} />

            <div className="py-8">
                <div className="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
                    <div className="mb-6 flex items-end gap-3">
                        <div>
                            <label className="block text-xs font-medium uppercase text-gray-500">{t('reports.as_of_date')}</label>
                            <input
                                type="date"
                                className="mt-1 rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
                                value={as_of}
                                onChange={(e) => router.get(route('reports.trial-balance'), { as_of: e.target.value }, { preserveState: true })}
                            />
                        </div>
                        {!balanced && (
                            <p className="text-sm font-semibold text-red-600 dark:text-red-400" data-testid="tb-unbalanced-warning">{t('reports.trial_balance_unbalanced')}</p>
                        )}
                    </div>

                    <div className="overflow-x-auto rounded-lg bg-white shadow-sm dark:bg-gray-800">
                        <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead className="bg-gray-50 dark:bg-gray-900/40">
                                <tr>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">{t('reports.account')}</th>
                                    <th className="px-4 py-3 text-right text-xs font-medium uppercase text-gray-500">{t('journal_entry.debit')}</th>
                                    <th className="px-4 py-3 text-right text-xs font-medium uppercase text-gray-500">{t('journal_entry.credit')}</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
                                {rows.map((r) => (
                                    <tr key={r.code} data-testid="tb-row">
                                        <td className="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">{r.code} {r.name}</td>
                                        <td className="px-4 py-3 text-right text-sm text-gray-700 dark:text-gray-300">{r.debit > 0 ? money(r.debit) : ''}</td>
                                        <td className="px-4 py-3 text-right text-sm text-gray-700 dark:text-gray-300">{r.credit > 0 ? money(r.credit) : ''}</td>
                                    </tr>
                                ))}
                                {rows.length === 0 && (
                                    <tr><td colSpan={3} className="px-4 py-6 text-center text-sm text-gray-400">{t('reports.no_activity')}</td></tr>
                                )}
                            </tbody>
                            <tfoot>
                                <tr className="border-t-2 border-gray-300 font-bold dark:border-gray-600">
                                    <td className="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">{t('reports.total')}</td>
                                    <td className="px-4 py-3 text-right text-sm text-gray-900 dark:text-gray-100" data-testid="tb-total-debit">{money(total_debit)}</td>
                                    <td className="px-4 py-3 text-right text-sm text-gray-900 dark:text-gray-100" data-testid="tb-total-credit">{money(total_credit)}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
