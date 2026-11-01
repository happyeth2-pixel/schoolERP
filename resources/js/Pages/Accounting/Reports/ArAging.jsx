import DateInput from '@/Components/DateInput';
import { BackLink } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

const money = (n) => Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const BUCKETS = ['current', 'd1_30', 'd31_60', 'd61_90', 'd90_plus'];

export default function ArAging({ as_of, rows, buckets, total }) {
    const { t } = useTranslation();

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">{t('reports.ar_aging_title')}</h2>}>
            <Head title={t('reports.ar_aging_title')} />

            <div className="py-8">
                <div className="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8">
                    <BackLink href={route('reports.index')}>{t('common.back')}</BackLink>
                    <div className="mb-6">
                        <label className="block text-xs font-medium uppercase text-stone-500">{t('reports.as_of_date')}</label>
                        <DateInput
                            className="mt-1"
                            value={as_of}
                            onChange={(v) => router.get(route('reports.ar-aging'), { as_of: v }, { preserveState: true })}
                        />
                    </div>

                    <div className="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-5" data-testid="aging-bucket-summary">
                        {BUCKETS.map((b) => (
                            <div key={b} className="rounded-lg bg-white p-3 text-center shadow-sm dark:bg-stone-800">
                                <p className="text-xs font-medium uppercase text-stone-500">{t(`reports.bucket_${b}`)}</p>
                                <p className="mt-1 text-sm font-semibold text-stone-900 dark:text-stone-100">{money(buckets[b] ?? 0)}</p>
                            </div>
                        ))}
                    </div>

                    <div className="overflow-x-auto rounded-lg bg-white shadow-sm dark:bg-stone-800">
                        <table className="min-w-full divide-y divide-stone-200 dark:divide-stone-700">
                            <thead className="bg-stone-50 dark:bg-stone-900/40">
                                <tr>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-stone-500">{t('reports.invoice')}</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-stone-500">{t('reports.student')}</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-stone-500">{t('reports.bucket')}</th>
                                    <th className="px-4 py-3 text-right text-xs font-medium uppercase text-stone-500">{t('reports.amount')}</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-stone-200 dark:divide-stone-700">
                                {rows.map((r) => (
                                    <tr key={r.invoice_number} data-testid="ar-aging-row">
                                        <td className="px-4 py-3 text-sm text-stone-900 dark:text-stone-100">{r.invoice_number}</td>
                                        <td className="px-4 py-3 text-sm text-stone-700 dark:text-stone-300">{r.student}</td>
                                        <td className="px-4 py-3 text-sm text-stone-700 dark:text-stone-300">{t(`reports.bucket_${r.bucket}`)}</td>
                                        <td className="px-4 py-3 text-right text-sm text-stone-700 dark:text-stone-300">{money(r.balance)}</td>
                                    </tr>
                                ))}
                                {rows.length === 0 && (
                                    <tr><td colSpan={4} className="px-4 py-6 text-center text-sm text-stone-400">{t('reports.no_activity')}</td></tr>
                                )}
                            </tbody>
                            <tfoot>
                                <tr className="border-t-2 border-stone-300 font-bold dark:border-stone-600">
                                    <td className="px-4 py-3 text-sm text-stone-900 dark:text-stone-100" colSpan={3}>{t('reports.total')}</td>
                                    <td className="px-4 py-3 text-right text-sm text-stone-900 dark:text-stone-100" data-testid="ar-aging-total">{money(total)}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
