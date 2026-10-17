import GradientStatTile from '@/Components/GradientStatTile';
import Pagination from '@/Components/Pagination';
import { Button, StatusBadge } from '@/Components/ui';
import SearchInput from '@/Components/SearchInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { ArrowDownTrayIcon, BanknotesIcon, DocumentTextIcon, ExclamationTriangleIcon, PlusIcon, ScaleIcon } from '@heroicons/react/24/outline';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';

// The list is paginated at the database level (InvoiceController@index): what's on screen is one page of the
// filtered set, the totals bar above it and the Export button below both cover the WHOLE filtered set regardless
// of which page is open. Search/grade/status all round-trip to the server (so a page can never show results that
// don't match what the totals say), debounced the same way the other "quick find" screens in this app are.

export default function Index({ invoices, invoicesMeta, totals, grades, branches, canManage, filters }) {
    const { t } = useTranslation();
    const { flash } = usePage().props;
    const [search, setSearch] = useState(filters?.q ?? '');
    const [gradeId, setGradeId] = useState(filters?.grade_id ?? '');
    const [status, setStatus] = useState(filters?.status ?? '');

    const go = (next, extra = {}) => {
        router.get(route('invoices.index'), {
            q: next.q ?? search,
            grade_id: next.gradeId ?? gradeId,
            status: next.status ?? status,
            ...extra,
        }, { preserveState: true, preserveScroll: true, replace: true });
    };

    // typing is debounced so each keystroke doesn't fire its own request; dropdown changes apply immediately
    useEffect(() => {
        const handle = setTimeout(() => {
            if (search !== (filters?.q ?? '')) go({ q: search }, { page: 1 });
        }, 300);
        return () => clearTimeout(handle);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const exportUrl = route('invoices.export', { q: search || undefined, grade_id: gradeId || undefined, status: status || undefined });

    return (
        <AuthenticatedLayout
            header={<h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">Invoices</h2>}
        >
            <Head title="Invoices" />

            <div className="py-8">
                <div className="mx-auto max-w-4xl space-y-4 px-4 sm:px-6 lg:px-8">
                    {flash?.success && (
                        <div className="rounded-md bg-green-50 px-4 py-3 text-sm text-green-700 dark:bg-green-900/30 dark:text-green-300">
                            {flash.success}
                        </div>
                    )}

                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <GradientStatTile icon={DocumentTextIcon} label="Billed (after discounts)" value={`${Number(totals.net).toLocaleString()} ETB`} color="blue" />
                        <GradientStatTile icon={BanknotesIcon} label="Collected (net of refunds)" value={`${Number(totals.collected).toLocaleString()} ETB`} color="green" />
                        <GradientStatTile icon={ScaleIcon} label="Outstanding" value={`${Number(totals.outstanding).toLocaleString()} ETB`} color="amber" />
                        <GradientStatTile icon={ExclamationTriangleIcon} label="Waived" value={`${Number(totals.waived).toLocaleString()} ETB`} color="red" />
                    </div>

                    <p className="text-xs text-gray-400">
                        Figures cover all {totals.invoice_count} matching invoice{totals.invoice_count === 1 ? '' : 's'} (current academic year), not just the page below. Billed is after discounts
                        ({Number(totals.discount).toLocaleString()} ETB given); collected is verified payments minus refunds ({Number(totals.refunded).toLocaleString()} ETB refunded);
                        outstanding excludes waived invoices.
                    </p>

                    {canManage && (
                        <div className="flex flex-wrap items-center justify-between gap-4 rounded-2xl border-2 border-indigo-200 bg-indigo-50 p-4 dark:border-indigo-800 dark:bg-indigo-900/20">
                            <div>
                                <p className="text-lg font-semibold text-gray-900 dark:text-gray-100">{t('gen.index_title')}</p>
                                <p className="text-base text-gray-700 dark:text-gray-300">{t('gen.index_desc')}</p>
                            </div>
                            <Button as={Link} href={route('invoices.wizard')} icon={PlusIcon}>{t('gen.index_button')}</Button>
                        </div>
                    )}

                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div className="flex flex-wrap items-center gap-2">
                            <div className="w-full max-w-xs">
                                <SearchInput value={search} onChange={setSearch} placeholder="Search student, invoice #…" />
                            </div>
                            <select
                                className="rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
                                value={gradeId}
                                onChange={(e) => { setGradeId(e.target.value); go({ gradeId: e.target.value }, { page: 1 }); }}
                            >
                                <option value="">All Grades</option>
                                {grades.map((g) => <option key={g.id} value={g.id}>{g.name}</option>)}
                            </select>
                            <select
                                className="rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
                                value={status}
                                onChange={(e) => { setStatus(e.target.value); go({ status: e.target.value }, { page: 1 }); }}
                            >
                                <option value="">All Statuses</option>
                                <option value="unpaid">Unpaid</option>
                                <option value="partially_paid">Partially Paid</option>
                                <option value="paid">Paid</option>
                                <option value="waived">Waived</option>
                            </select>
                        </div>
                        <a
                            href={exportUrl}
                            className="inline-flex items-center gap-1.5 rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
                        >
                            <ArrowDownTrayIcon className="h-4 w-4" /> Export to Excel
                        </a>
                    </div>

                    <div className="overflow-x-auto rounded-lg bg-white shadow-sm dark:bg-gray-800">
                        <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead className="bg-gray-50 dark:bg-gray-900/40">
                                <tr>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Invoice #</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Student</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Grade</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Period</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Total</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Paid</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Balance</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Status</th>
                                    <th className="px-4 py-3" />
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
                                {invoices.map((i) => (
                                    <tr key={i.id} data-testid="invoice-row">
                                        <td className="px-4 py-3 text-sm text-gray-500">{i.invoice_number}</td>
                                        <td className="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">{i.student}</td>
                                        <td className="px-4 py-3 text-sm text-gray-500">{i.grade ?? '—'}</td>
                                        <td className="px-4 py-3 text-sm text-gray-500">{i.period}</td>
                                        <td className="px-4 py-3 text-sm text-gray-500">{Number(i.total).toLocaleString()}</td>
                                        <td className="px-4 py-3 text-sm text-gray-500">{Number(i.paid).toLocaleString()}</td>
                                        <td className="px-4 py-3 text-sm text-gray-500">{Number(i.balance).toLocaleString()}</td>
                                        <td className="px-4 py-3 text-sm">
                                            <StatusBadge status={i.status} />
                                        </td>
                                        <td className="px-4 py-3 text-right text-sm">
                                            <Link href={route('invoices.show', i.id)} className="text-indigo-600 hover:underline dark:text-indigo-400">View</Link>
                                        </td>
                                    </tr>
                                ))}
                                {invoices.length === 0 && (
                                    <tr><td colSpan={9} className="px-4 py-6 text-center text-sm text-gray-400">
                                        {totals.invoice_count === 0 ? t('invoices.list_empty_year') : t('invoices.list_empty_search')}
                                    </td></tr>
                                )}
                            </tbody>
                        </table>
                    </div>

                    <Pagination meta={invoicesMeta} onPageChange={(page) => go({}, { page })} />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
