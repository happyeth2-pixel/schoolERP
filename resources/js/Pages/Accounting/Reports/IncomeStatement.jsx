import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

const money = (n) => Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

export default function IncomeStatement({ from, to, revenue, expense, total_revenue, total_expense, net_income }) {
    const { t } = useTranslation();

    const update = (patch) => router.get(route('reports.income-statement'), {
        from: patch.from ?? from,
        to: patch.to ?? to,
    }, { preserveState: true });

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">{t('reports.income_statement_title')}</h2>}>
            <Head title={t('reports.income_statement_title')} />

            <div className="py-8">
                <div className="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
                    <div className="mb-6 flex flex-wrap items-end gap-3">
                        <div>
                            <label className="block text-xs font-medium uppercase text-gray-500">{t('reports.from_date')}</label>
                            <input type="date" className="mt-1 rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100" value={from ?? ''} onChange={(e) => update({ from: e.target.value })} />
                        </div>
                        <div>
                            <label className="block text-xs font-medium uppercase text-gray-500">{t('reports.to_date')}</label>
                            <input type="date" className="mt-1 rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100" value={to ?? ''} onChange={(e) => update({ to: e.target.value })} />
                        </div>
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
                                <tr><td colSpan={2} className="px-4 py-2 text-xs font-semibold uppercase text-gray-500">{t('reports.revenue')}</td></tr>
                                {revenue.map((r) => (
                                    <tr key={r.code ?? r.name} data-testid="is-revenue-row">
                                        <td className="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">{r.code} {r.name}</td>
                                        <td className="px-4 py-3 text-right text-sm text-gray-700 dark:text-gray-300">{money(r.amount)}</td>
                                    </tr>
                                ))}
                                <tr className="font-semibold">
                                    <td className="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">{t('reports.total_revenue')}</td>
                                    <td className="px-4 py-3 text-right text-sm text-gray-900 dark:text-gray-100" data-testid="is-total-revenue">{money(total_revenue)}</td>
                                </tr>

                                <tr><td colSpan={2} className="px-4 py-2 text-xs font-semibold uppercase text-gray-500">{t('reports.expense')}</td></tr>
                                {expense.map((r) => (
                                    <tr key={r.code ?? r.name} data-testid="is-expense-row">
                                        <td className="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">{r.code} {r.name}</td>
                                        <td className="px-4 py-3 text-right text-sm text-gray-700 dark:text-gray-300">{money(r.amount)}</td>
                                    </tr>
                                ))}
                                <tr className="font-semibold">
                                    <td className="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">{t('reports.total_expense')}</td>
                                    <td className="px-4 py-3 text-right text-sm text-gray-900 dark:text-gray-100" data-testid="is-total-expense">{money(total_expense)}</td>
                                </tr>

                                {revenue.length === 0 && expense.length === 0 && (
                                    <tr><td colSpan={2} className="px-4 py-6 text-center text-sm text-gray-400">{t('reports.no_activity')}</td></tr>
                                )}
                            </tbody>
                            <tfoot>
                                <tr className="border-t-2 border-gray-300 font-bold dark:border-gray-600">
                                    <td className="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">{t('reports.net_income')}</td>
                                    <td className={`px-4 py-3 text-right text-sm ${net_income < 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-900 dark:text-gray-100'}`} data-testid="is-net-income">{money(net_income)}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
