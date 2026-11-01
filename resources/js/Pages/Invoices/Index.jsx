import DateInput from '@/Components/DateInput';
import GradientStatTile from '@/Components/GradientStatTile';
import Pagination from '@/Components/Pagination';
import { BackLink, Button, StatusBadge, useConfirm } from '@/Components/ui';
import SearchInput from '@/Components/SearchInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { ArrowDownTrayIcon, BanknotesIcon, DocumentTextIcon, ExclamationTriangleIcon, PlusIcon, ScaleIcon, TrashIcon } from '@heroicons/react/24/outline';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';

// The list is paginated at the database level (InvoiceController@index): what's on screen is one page of the
// filtered set, the totals bar above it and the Export button below both cover the WHOLE filtered set regardless
// of which page is open. Search/grade/status all round-trip to the server (so a page can never show results that
// don't match what the totals say), debounced the same way the other "quick find" screens in this app are.

const FREQUENCY_LABEL = { annual: 'Annual', termly: 'Termly', monthly: 'Monthly', one_time: 'One-time' };

export default function Index({ invoices, invoicesMeta, totals, grades, branches, academicYears, selectedAcademicYearId, periods, canManage, filters }) {
    const { t } = useTranslation();
    const { flash } = usePage().props;
    const [search, setSearch] = useState(filters?.q ?? '');
    const [gradeId, setGradeId] = useState(filters?.grade_id ?? '');
    const [status, setStatus] = useState(filters?.status ?? '');
    const [academicYearId, setAcademicYearId] = useState(filters?.academic_year_id ?? String(selectedAcademicYearId ?? ''));
    const [frequency, setFrequency] = useState(filters?.frequency ?? '');
    const [period, setPeriod] = useState(filters?.period ?? '');
    const [sentFrom, setSentFrom] = useState(filters?.sent_from ?? '');
    const [sentTo, setSentTo] = useState(filters?.sent_to ?? '');
    const [selected, setSelected] = useState([]);
    const [ask, confirmDialog] = useConfirm();

    // selection is page-local: switching filters/pages starts clean rather than carrying picks the
    // viewer can no longer see, which would make "delete selected" delete bills they can't verify
    useEffect(() => { setSelected([]); }, [invoices]);

    const toggleOne = (id) => setSelected((s) => (s.includes(id) ? s.filter((x) => x !== id) : [...s, id]));
    const toggleAll = () => setSelected((s) => (s.length === invoices.length ? [] : invoices.map((i) => i.id)));

    const deleteSelected = async () => {
        const ok = await ask({
            title: `Delete ${selected.length} bill${selected.length === 1 ? '' : 's'}?`,
            body: <p>Any of these that already have a payment on them will be left alone instead.</p>,
            confirmLabel: 'Delete',
            tone: 'danger',
        });
        if (ok) router.delete(route('invoices.destroy-many'), { data: { ids: selected } });
    };

    const go = (next, extra = {}) => {
        router.get(route('invoices.index'), {
            q: next.q ?? search,
            grade_id: next.gradeId ?? gradeId,
            status: next.status ?? status,
            academic_year_id: next.academicYearId ?? academicYearId,
            frequency: next.frequency ?? frequency,
            period: next.period ?? period,
            sent_from: next.sentFrom ?? sentFrom,
            sent_to: next.sentTo ?? sentTo,
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

    // changing the type (or the year) can make the previously chosen specific period stale -- e.g. picking
    // "Monthly" after a term was selected -- so it is cleared rather than silently filtering on a mismatch
    const chooseFrequency = (value) => {
        setFrequency(value);
        setPeriod('');
        go({ frequency: value, period: '' }, { page: 1 });
    };
    const chooseAcademicYear = (value) => {
        setAcademicYearId(value);
        setPeriod('');
        go({ academicYearId: value, period: '' }, { page: 1 });
    };

    const periodOptions = periods.filter((p) => !frequency || p.frequency === frequency);

    const exportUrl = route('invoices.export', {
        q: search || undefined,
        grade_id: gradeId || undefined,
        status: status || undefined,
        academic_year_id: academicYearId || undefined,
        frequency: frequency || undefined,
        period: period || undefined,
        sent_from: sentFrom || undefined,
        sent_to: sentTo || undefined,
    });

    return (
        <AuthenticatedLayout
            header={<h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">Invoices</h2>}
        >
            <Head title="Invoices" />

            <div className="py-8">
                <div className="mx-auto max-w-4xl space-y-4 px-4 sm:px-6 lg:px-8">
                    <BackLink href={route('dashboard')}>{t('common.back')}</BackLink>
                    {flash?.success && (
                        <div className="rounded-md bg-green-50 px-4 py-3 text-sm text-green-700 dark:bg-green-900/30 dark:text-green-300">
                            {flash.success}
                        </div>
                    )}
                    {flash?.warning && (
                        <div className="rounded-md bg-amber-50 px-4 py-3 text-sm text-amber-700 dark:bg-amber-900/30 dark:text-amber-300">
                            {flash.warning}
                        </div>
                    )}

                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <GradientStatTile icon={DocumentTextIcon} label="Billed (after discounts)" value={`${Number(totals.net).toLocaleString()} ETB`} color="blue" />
                        <GradientStatTile icon={BanknotesIcon} label="Collected (net of refunds)" value={`${Number(totals.collected).toLocaleString()} ETB`} color="green" />
                        <GradientStatTile icon={ScaleIcon} label="Outstanding" value={`${Number(totals.outstanding).toLocaleString()} ETB`} color="amber" />
                        <GradientStatTile icon={ExclamationTriangleIcon} label="Waived" value={`${Number(totals.waived).toLocaleString()} ETB`} color="red" />
                    </div>

                    <p className="text-xs text-stone-400">
                        Figures cover all {totals.invoice_count} matching invoice{totals.invoice_count === 1 ? '' : 's'}
                        {academicYears.length > 0 && (academicYears.find((y) => String(y.id) === String(academicYearId))?.name ? ` (${academicYears.find((y) => String(y.id) === String(academicYearId))?.name})` : '')}, not just the page below. Billed is after discounts
                        ({Number(totals.discount).toLocaleString()} ETB given); collected is verified payments minus refunds ({Number(totals.refunded).toLocaleString()} ETB refunded);
                        outstanding excludes waived invoices.
                    </p>

                    {canManage && (
                        <div className="flex flex-wrap items-center justify-between gap-4 rounded-2xl border-2 border-brand-200 bg-brand-50 p-4 dark:border-brand-800 dark:bg-brand-900/20">
                            <div>
                                <p className="text-lg font-semibold text-stone-900 dark:text-stone-100">{t('gen.index_title')}</p>
                                <p className="text-base text-stone-700 dark:text-stone-300">{t('gen.index_desc')}</p>
                            </div>
                            <Button as={Link} href={route('invoices.wizard')} icon={PlusIcon}>{t('gen.index_button')}</Button>
                        </div>
                    )}

                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div className="flex flex-wrap items-center gap-2">
                            <div className="w-full max-w-xs">
                                <SearchInput value={search} onChange={setSearch} placeholder="Search student, invoice #…" />
                            </div>
                            {academicYears.length > 1 && (
                                <select
                                    className="rounded-md border-stone-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500 dark:border-stone-700 dark:bg-stone-900 dark:text-stone-100"
                                    value={academicYearId}
                                    onChange={(e) => chooseAcademicYear(e.target.value)}
                                    data-testid="filter-academic-year"
                                >
                                    {academicYears.map((y) => <option key={y.id} value={y.id}>{y.name}{y.is_current ? ' (current)' : ''}</option>)}
                                </select>
                            )}
                            <select
                                className="rounded-md border-stone-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500 dark:border-stone-700 dark:bg-stone-900 dark:text-stone-100"
                                value={gradeId}
                                onChange={(e) => { setGradeId(e.target.value); go({ gradeId: e.target.value }, { page: 1 }); }}
                            >
                                <option value="">All Grades</option>
                                {grades.map((g) => <option key={g.id} value={g.id}>{g.name}</option>)}
                            </select>
                            <select
                                className="rounded-md border-stone-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500 dark:border-stone-700 dark:bg-stone-900 dark:text-stone-100"
                                value={frequency}
                                onChange={(e) => chooseFrequency(e.target.value)}
                                data-testid="filter-frequency"
                            >
                                <option value="">All Types</option>
                                {Object.entries(FREQUENCY_LABEL).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                            </select>
                            <select
                                className="rounded-md border-stone-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500 dark:border-stone-700 dark:bg-stone-900 dark:text-stone-100"
                                value={period}
                                onChange={(e) => { setPeriod(e.target.value); go({ period: e.target.value }, { page: 1 }); }}
                                data-testid="filter-period"
                            >
                                <option value="">All Periods</option>
                                {periodOptions.map((p) => <option key={p.value} value={p.value}>{p.label}</option>)}
                            </select>
                            <select
                                className="rounded-md border-stone-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500 dark:border-stone-700 dark:bg-stone-900 dark:text-stone-100"
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
                            className="inline-flex items-center gap-1.5 rounded-md border border-stone-300 bg-white px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-stone-600 dark:bg-stone-800 dark:text-stone-200 dark:hover:bg-stone-700"
                        >
                            <ArrowDownTrayIcon className="h-4 w-4" /> Export to Excel
                        </a>
                    </div>

                    <div className="flex flex-wrap items-end gap-2">
                        <div>
                            <label htmlFor="sent-from" className="mb-1 block text-xs font-medium uppercase text-stone-500">Sent from</label>
                            <DateInput id="sent-from" value={sentFrom} max={sentTo || undefined} onChange={(v) => { setSentFrom(v); go({ sentFrom: v }, { page: 1 }); }} />
                        </div>
                        <div>
                            <label htmlFor="sent-to" className="mb-1 block text-xs font-medium uppercase text-stone-500">Sent to</label>
                            <DateInput id="sent-to" value={sentTo} min={sentFrom || undefined} onChange={(v) => { setSentTo(v); go({ sentTo: v }, { page: 1 }); }} />
                        </div>
                        {(sentFrom || sentTo) && (
                            <Button
                                variant="quiet"
                                size="md"
                                onClick={() => { setSentFrom(''); setSentTo(''); go({ sentFrom: '', sentTo: '' }, { page: 1 }); }}
                            >
                                Clear
                            </Button>
                        )}
                    </div>

                    {canManage && selected.length > 0 && (
                        <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border-2 border-red-200 bg-red-50 px-4 py-3 dark:border-red-800 dark:bg-red-900/20" data-testid="bulk-delete-bar">
                            <p className="text-sm font-medium text-red-900 dark:text-red-200">{selected.length} selected</p>
                            <Button variant="danger" size="md" icon={TrashIcon} onClick={deleteSelected} data-testid="delete-selected">
                                Delete selected
                            </Button>
                        </div>
                    )}

                    <div className="overflow-x-auto rounded-lg bg-white shadow-sm dark:bg-stone-800">
                        <table className="min-w-full divide-y divide-stone-200 dark:divide-stone-700">
                            <thead className="bg-stone-50 dark:bg-stone-900/40">
                                <tr>
                                    {canManage && (
                                        <th className="px-4 py-3">
                                            <input
                                                type="checkbox"
                                                aria-label="Select all"
                                                checked={invoices.length > 0 && selected.length === invoices.length}
                                                onChange={toggleAll}
                                                className="h-4 w-4 rounded border-stone-300 text-brand-600 focus:ring-brand-500"
                                            />
                                        </th>
                                    )}
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-stone-500">Invoice #</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-stone-500">Student</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-stone-500">Grade</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-stone-500">Period</th>
                                    <th className="px-4 py-3 text-right text-xs font-medium uppercase text-stone-500">Total</th>
                                    <th className="px-4 py-3 text-right text-xs font-medium uppercase text-stone-500">Paid</th>
                                    <th className="px-4 py-3 text-right text-xs font-medium uppercase text-stone-500">Balance</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-stone-500">Status</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-stone-500">Sent</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-stone-500">Paid On</th>
                                    <th className="px-4 py-3" />
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-stone-200 dark:divide-stone-700">
                                {invoices.map((i) => (
                                    <tr
                                        key={i.id}
                                        data-testid="invoice-row"
                                        onClick={() => router.visit(route('invoices.show', i.id))}
                                        className="cursor-pointer hover:bg-stone-50 dark:hover:bg-stone-700/40"
                                    >
                                        {canManage && (
                                            <td className="px-4 py-3" onClick={(e) => e.stopPropagation()}>
                                                <input
                                                    type="checkbox"
                                                    aria-label={`Select invoice ${i.invoice_number}`}
                                                    checked={selected.includes(i.id)}
                                                    onChange={() => toggleOne(i.id)}
                                                    className="h-4 w-4 rounded border-stone-300 text-brand-600 focus:ring-brand-500"
                                                />
                                            </td>
                                        )}
                                        <td className="px-4 py-3 text-sm text-stone-500">{i.invoice_number}</td>
                                        <td className="px-4 py-3 text-sm text-stone-900 dark:text-stone-100">{i.student}</td>
                                        <td className="px-4 py-3 text-sm text-stone-500">{i.grade ?? '—'}</td>
                                        <td className="px-4 py-3 text-sm text-stone-500">
                                            {i.period}
                                            <span className="block text-xs text-stone-400">{FREQUENCY_LABEL[i.frequency] ?? i.frequency}</span>
                                        </td>
                                        <td className="tabular px-4 py-3 text-right text-sm text-stone-700 dark:text-stone-300">{Number(i.total).toLocaleString()}</td>
                                        <td className="tabular px-4 py-3 text-right text-sm text-stone-700 dark:text-stone-300">{Number(i.paid).toLocaleString()}</td>
                                        <td className="tabular px-4 py-3 text-right text-sm font-semibold text-stone-900 dark:text-stone-100">{Number(i.balance).toLocaleString()}</td>
                                        <td className="px-4 py-3 text-sm">
                                            <div className="flex flex-wrap items-center gap-1.5">
                                                <StatusBadge status={i.status} />
                                                {i.is_overdue && (
                                                    <span className="inline-flex items-center gap-1 rounded-full border border-red-300 px-2.5 py-0.5 text-xs font-medium text-red-700 dark:border-red-800 dark:text-red-400">
                                                        {t('invoices.overdue_badge')}
                                                    </span>
                                                )}
                                            </div>
                                        </td>
                                        <td className="whitespace-nowrap px-4 py-3 text-sm text-stone-500" data-testid="invoice-sent-at">{i.sent_at}</td>
                                        <td className="px-4 py-3 text-sm text-stone-500" data-testid="invoice-paid-on">{i.paid_at ?? '—'}</td>
                                        <td className="px-4 py-3 text-right text-sm">
                                            <Link href={route('invoices.show', i.id)} onClick={(e) => e.stopPropagation()} className="text-brand-600 hover:underline dark:text-brand-400">View</Link>
                                        </td>
                                    </tr>
                                ))}
                                {invoices.length === 0 && (
                                    <tr><td colSpan={canManage ? 12 : 11} className="px-4 py-6 text-center text-sm text-stone-400">
                                        {totals.invoice_count === 0 ? t('invoices.list_empty_year') : t('invoices.list_empty_search')}
                                    </td></tr>
                                )}
                            </tbody>
                        </table>
                    </div>

                    <Pagination meta={invoicesMeta} onPageChange={(page) => go({}, { page })} />
                </div>
            </div>
            {confirmDialog}
        </AuthenticatedLayout>
    );
}
