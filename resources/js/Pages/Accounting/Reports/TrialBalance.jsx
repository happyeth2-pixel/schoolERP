import DateInput from '@/Components/DateInput';
import { BackLink } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

const money = (n) => Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

export default function TrialBalance({ as_of, rows, total_debit, total_credit }) {
    const { t } = useTranslation();
    const balanced = Math.abs(total_debit - total_credit) < 0.005;

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">{t('reports.trial_balance_title')}</h2>}>
            <Head title={t('reports.trial_balance_title')} />

            <div className="py-8">
                <div className="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
                    <BackLink href={route('reports.index')}>{t('common.back')}</BackLink>
                    <div className="mb-6 flex items-end gap-3">
                        <div>
                            <label className="block text-xs font-medium uppercase text-stone-500">{t('reports.as_of_date')}</label>
                            <DateInput
                                className="mt-1"
                                value={as_of}
                                onChange={(v) => router.get(route('reports.trial-balance'), { as_of: v }, { preserveState: true })}
                            />
                        </div>
                        {!balanced && (
                            <p className="text-sm font-semibold text-red-600 dark:text-red-400" data-testid="tb-unbalanced-warning">{t('reports.trial_balance_unbalanced')}</p>
                        )}
                    </div>

                    <div className="overflow-x-auto rounded-lg bg-white shadow-sm dark:bg-stone-800">
                        <table className="min-w-full divide-y divide-stone-200 dark:divide-stone-700">
                            <thead className="bg-stone-50 dark:bg-stone-900/40">
                                <tr>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-stone-500">{t('reports.account')}</th>
                                    <th className="px-4 py-3 text-right text-xs font-medium uppercase text-stone-500">{t('journal_entry.debit')}</th>
                                    <th className="px-4 py-3 text-right text-xs font-medium uppercase text-stone-500">{t('journal_entry.credit')}</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-stone-200 dark:divide-stone-700">
                                {rows.map((r) => (
                                    <tr key={r.code} data-testid="tb-row">
                                        <td className="px-4 py-3 text-sm text-stone-900 dark:text-stone-100">{r.code} {r.name}</td>
                                        <td className="px-4 py-3 text-right text-sm text-stone-700 dark:text-stone-300">{r.debit > 0 ? money(r.debit) : ''}</td>
                                        <td className="px-4 py-3 text-right text-sm text-stone-700 dark:text-stone-300">{r.credit > 0 ? money(r.credit) : ''}</td>
                                    </tr>
                                ))}
                                {rows.length === 0 && (
                                    <tr><td colSpan={3} className="px-4 py-6 text-center text-sm text-stone-400">{t('reports.no_activity')}</td></tr>
                                )}
                            </tbody>
                            <tfoot>
                                <tr className="border-t-2 border-stone-300 font-bold dark:border-stone-600">
                                    <td className="px-4 py-3 text-sm text-stone-900 dark:text-stone-100">{t('reports.total')}</td>
                                    <td className="px-4 py-3 text-right text-sm text-stone-900 dark:text-stone-100" data-testid="tb-total-debit">{money(total_debit)}</td>
                                    <td className="px-4 py-3 text-right text-sm text-stone-900 dark:text-stone-100" data-testid="tb-total-credit">{money(total_credit)}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
