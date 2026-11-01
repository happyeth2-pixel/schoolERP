import DateInput from '@/Components/DateInput';
import { BackLink } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

const money = (n) => Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

export default function Ledger({ accounts, account_id, from, to, opening_balance, rows }) {
    const { t } = useTranslation();

    const update = (patch) => router.get(route('reports.ledger'), {
        account_id: patch.account_id ?? account_id,
        from: patch.from ?? from,
        to: patch.to ?? to,
    }, { preserveState: true });

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">{t('reports.ledger_title')}</h2>}>
            <Head title={t('reports.ledger_title')} />

            <div className="py-8">
                <div className="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8">
                    <BackLink href={route('reports.index')}>{t('common.back')}</BackLink>
                    <div className="mb-6 flex flex-wrap items-end gap-3">
                        <div>
                            <label className="block text-xs font-medium uppercase text-stone-500">{t('reports.account')}</label>
                            <select
                                className="mt-1 rounded-md border-stone-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500 dark:border-stone-700 dark:bg-stone-900 dark:text-stone-100"
                                value={account_id ?? ''}
                                onChange={(e) => update({ account_id: e.target.value })}
                            >
                                {accounts.map((a) => <option key={a.id} value={a.id}>{a.code} {a.name}</option>)}
                            </select>
                        </div>
                        <div>
                            <label className="block text-xs font-medium uppercase text-stone-500">{t('reports.from_date')}</label>
                            <DateInput className="mt-1" value={from ?? ''} onChange={(v) => update({ from: v })} />
                        </div>
                        <div>
                            <label className="block text-xs font-medium uppercase text-stone-500">{t('reports.to_date')}</label>
                            <DateInput className="mt-1" value={to ?? ''} onChange={(v) => update({ to: v })} />
                        </div>
                    </div>

                    <div className="overflow-x-auto rounded-lg bg-white shadow-sm dark:bg-stone-800">
                        <table className="min-w-full divide-y divide-stone-200 dark:divide-stone-700">
                            <thead className="bg-stone-50 dark:bg-stone-900/40">
                                <tr>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-stone-500">{t('reports.date')}</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-stone-500">{t('reports.entry_number')}</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-stone-500">{t('reports.description')}</th>
                                    <th className="px-4 py-3 text-right text-xs font-medium uppercase text-stone-500">{t('journal_entry.debit')}</th>
                                    <th className="px-4 py-3 text-right text-xs font-medium uppercase text-stone-500">{t('journal_entry.credit')}</th>
                                    <th className="px-4 py-3 text-right text-xs font-medium uppercase text-stone-500">{t('reports.running_balance')}</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-stone-200 dark:divide-stone-700">
                                <tr data-testid="ledger-opening-row">
                                    <td className="px-4 py-3 text-sm text-stone-500" colSpan={5}>{t('reports.opening_balance')}</td>
                                    <td className="px-4 py-3 text-right text-sm font-semibold text-stone-900 dark:text-stone-100">{money(opening_balance)}</td>
                                </tr>
                                {rows.map((r, i) => (
                                    <tr key={i} data-testid="ledger-row">
                                        <td className="px-4 py-3 text-sm text-stone-500">{r.entry_date}</td>
                                        <td className="px-4 py-3 text-sm text-stone-500">{r.entry_number}</td>
                                        <td className="px-4 py-3 text-sm text-stone-900 dark:text-stone-100">{r.description}</td>
                                        <td className="px-4 py-3 text-right text-sm text-stone-700 dark:text-stone-300">{r.debit > 0 ? money(r.debit) : ''}</td>
                                        <td className="px-4 py-3 text-right text-sm text-stone-700 dark:text-stone-300">{r.credit > 0 ? money(r.credit) : ''}</td>
                                        <td className="px-4 py-3 text-right text-sm font-semibold text-stone-900 dark:text-stone-100">{money(r.running_balance)}</td>
                                    </tr>
                                ))}
                                {rows.length === 0 && (
                                    <tr><td colSpan={6} className="px-4 py-6 text-center text-sm text-stone-400">{t('reports.no_activity')}</td></tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
