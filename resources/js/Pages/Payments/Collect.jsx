import { Alert, BackLink, Button, Card, ChoiceGroup, EmptyState, FlashMessages, Page } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { BanknotesIcon, ChevronRightIcon } from '@heroicons/react/24/outline';
import { router, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';

// "A parent walks in with cash and pays a bit of everything at once": pick a class, see every student
// in one table, a box per thing they could owe (tuition, each extra service they are actually enrolled
// in, books), fill in only what this family is paying now -- it can be less than the full amount; the
// rest just stays owed, same as any other payment. This only COLLECTS money against what is already
// billed -- enrolling someone in a service or selling them a book still happens on those own pages.

const METHODS = ['cash', 'bank_transfer', 'mobile_money', 'other'];
const money = (n) => Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

function Cell({ owed, value, onChange, notAssignedLabel }) {
    const { t } = useTranslation();

    if (notAssignedLabel) {
        return <span className="block py-2 text-center text-sm text-stone-500 dark:text-stone-400">{notAssignedLabel}</span>;
    }
    if (owed <= 0.001) {
        return <span className="block py-2 text-center text-sm text-stone-500 dark:text-stone-400">{t('collect.nothing_owed')}</span>;
    }

    return (
        <div className="flex flex-col items-center gap-0.5">
            <input
                type="text"
                inputMode="decimal"
                autoComplete="off"
                value={value}
                onChange={(e) => onChange(e.target.value)}
                placeholder="0.00"
                aria-label={t('collect.amount_input_label', { max: owed })}
                className="h-11 w-24 rounded-lg border-2 border-stone-400 bg-white text-center text-base font-semibold text-stone-900 shadow-sm focus:border-brand-600 focus:ring-2 focus:ring-brand-200 dark:border-stone-500 dark:bg-stone-900 dark:text-stone-100"
            />
            <span className="text-xs text-stone-500 dark:text-stone-400">{t('collect.owed_hint', { amount: money(owed) })}</span>
        </div>
    );
}

function Grid({ section, grid, sections }) {
    const { t } = useTranslation();
    const [search, setSearch] = useState('');
    const initialRows = useMemo(() => Object.fromEntries(grid.students.map((s) => [
        s.id,
        { tuition: '', book: '', services: Object.fromEntries(s.services.map((sv) => [sv.service_id, ''])) },
    ])), [grid]);
    const [rows, setRows] = useState(initialRows);
    const { data, setData, post, processing, errors } = useForm({ method: 'cash' });

    const setCell = (studentId, key, value) => setRows((r) => ({ ...r, [studentId]: { ...r[studentId], [key]: value } }));
    const setServiceCell = (studentId, serviceId, value) => setRows((r) => ({
        ...r,
        [studentId]: { ...r[studentId], services: { ...r[studentId].services, [serviceId]: value } },
    }));

    const needle = search.trim().toLowerCase();
    const visible = needle === '' ? grid.students : grid.students.filter((s) => s.name.toLowerCase().includes(needle) || s.student_id_number?.toLowerCase().includes(needle));

    const rowTotal = (studentId) => {
        const r = rows[studentId];
        const n = (v) => (v === '' || isNaN(Number(v)) ? 0 : Number(v));
        return n(r.tuition) + n(r.book) + Object.values(r.services).reduce((a, v) => a + n(v), 0);
    };
    const grandTotal = grid.students.reduce((sum, s) => sum + rowTotal(s.id), 0);
    const studentsWithAmount = grid.students.filter((s) => rowTotal(s.id) > 0).length;

    const submit = () => {
        const rowsPayload = grid.students
            .map((s) => ({
                student_id: s.id,
                tuition: rows[s.id].tuition || 0,
                book: rows[s.id].book || 0,
                services: s.services.map((sv) => ({ service_id: sv.service_id, amount: rows[s.id].services[sv.service_id] || 0 })),
            }))
            .filter((r) => Number(r.tuition) > 0 || Number(r.book) > 0 || r.services.some((sv) => Number(sv.amount) > 0));

        router.post(route('payments.collect.store', section.id), { method: data.method, rows: rowsPayload }, {
            preserveScroll: true,
            onSuccess: () => setRows(initialRows),
        });
    };

    return (
        <>
            <Card>
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p className="text-base font-semibold text-stone-900 dark:text-stone-100">{t('collect.students_count', { count: grid.students.length })}</p>
                        <p className="text-sm text-stone-600 dark:text-stone-400">{t('collect.partial_hint')}</p>
                    </div>
                    <input
                        type="text"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder={t('collect.search_placeholder')}
                        autoComplete="off"
                        className="h-11 w-56 rounded-lg border-stone-300 bg-white px-3 text-base text-stone-900 placeholder:text-stone-400 focus:border-brand-600 focus:ring-2 focus:ring-brand-600 dark:border-stone-600 dark:bg-stone-900 dark:text-stone-100"
                    />
                </div>
            </Card>

            {grid.students.length === 0 ? (
                <Card><EmptyState title={t('collect.empty_title')}>{t('collect.empty_body')}</EmptyState></Card>
            ) : (
                <Card className="overflow-x-auto">
                    <table className="w-full min-w-[640px] border-collapse">
                        <thead>
                            <tr className="border-b-2 border-stone-200 text-left text-sm font-semibold text-stone-700 dark:border-stone-700 dark:text-stone-300">
                                <th className="sticky left-0 bg-white py-2 pr-3 dark:bg-stone-800">{t('collect.col_student')}</th>
                                <th className="px-3 py-2 text-center">{t('collect.col_tuition')}</th>
                                {grid.services.map((svc) => <th key={svc.id} className="px-3 py-2 text-center">{svc.name}</th>)}
                                <th className="px-3 py-2 text-center">{t('collect.col_books')}</th>
                                <th className="px-3 py-2 text-center">{t('collect.col_total')}</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-stone-100 dark:divide-stone-700">
                            {visible.map((s) => {
                                const servicesById = Object.fromEntries(s.services.map((sv) => [sv.service_id, sv]));

                                return (
                                    <tr key={s.id} data-testid="collect-row">
                                        <td className="sticky left-0 whitespace-nowrap bg-white py-3 pr-3 dark:bg-stone-800">
                                            <p className="font-semibold text-stone-900 dark:text-stone-100">{s.name}</p>
                                            <p className="text-xs text-stone-500 dark:text-stone-400">{s.student_id_number}</p>
                                        </td>
                                        <td className="px-3 py-3">
                                            <Cell owed={s.tuition_owed} value={rows[s.id].tuition} onChange={(v) => setCell(s.id, 'tuition', v)} />
                                        </td>
                                        {grid.services.map((svc) => {
                                            const sv = servicesById[svc.id];
                                            return (
                                                <td key={svc.id} className="px-3 py-3">
                                                    <Cell
                                                        owed={sv?.owed ?? 0}
                                                        notAssignedLabel={!sv?.assigned ? t('collect.not_assigned') : null}
                                                        value={rows[s.id].services[svc.id] ?? ''}
                                                        onChange={(v) => setServiceCell(s.id, svc.id, v)}
                                                    />
                                                </td>
                                            );
                                        })}
                                        <td className="px-3 py-3">
                                            <Cell owed={s.book_owed} value={rows[s.id].book} onChange={(v) => setCell(s.id, 'book', v)} />
                                        </td>
                                        <td className="px-3 py-3 text-center text-base font-bold text-stone-900 dark:text-stone-100" data-testid="row-total">
                                            {rowTotal(s.id) > 0 ? money(rowTotal(s.id)) : '—'}
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </Card>
            )}

            {grid.students.length > 0 && (
                <Card>
                    <ChoiceGroup
                        heading={t('collect.how_paid')}
                        layout="segmented"
                        value={data.method}
                        onChange={(v) => setData('method', v)}
                        options={METHODS.map((m) => ({ value: m, label: t(`verify.method_${m}`) }))}
                    />
                    {errors.rows && <Alert tone="danger" className="mt-4">{errors.rows}</Alert>}
                    <div className="mt-5 flex flex-wrap items-center justify-between gap-4 border-t border-stone-200 pt-4 dark:border-stone-700">
                        <p className="text-lg font-bold text-stone-900 dark:text-stone-100" data-testid="grand-total">{t('collect.grand_total', { amount: money(grandTotal) })}</p>
                        <Button icon={BanknotesIcon} busy={processing} disabled={studentsWithAmount === 0} onClick={submit}>
                            {t('collect.submit_button', { count: studentsWithAmount })}
                        </Button>
                    </div>
                </Card>
            )}
        </>
    );
}

export default function Collect({ sections, focus, grid, branchCount }) {
    const { t } = useTranslation();
    const [selectedId, setSelectedId] = useState(focus && sections.some((s) => s.id === focus) ? focus : null);
    const [filter, setFilter] = useState('');

    const header = <h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">{t('collect.page_title')}</h2>;

    const openSection = (id) => {
        setSelectedId(id);
        router.get(route('payments.collect'), { section: id }, { preserveState: true, preserveScroll: true });
    };

    if (selectedId && grid) {
        return (
            <AuthenticatedLayout header={header}>
                <Page
                    title={grid.section.label}
                    description={t('collect.page_desc')}
                    back={<BackLink href={route('payments.collect')}>{t('collect.back_to_classes')}</BackLink>}
                >
                    <FlashMessages />
                    <Grid key={grid.section.id} section={grid.section} grid={grid} sections={sections} />
                </Page>
            </AuthenticatedLayout>
        );
    }

    const q = filter.trim().toLowerCase();
    const shown = q ? sections.filter((s) => `${s.label} ${s.branch}`.toLowerCase().includes(q)) : sections;

    return (
        <AuthenticatedLayout header={header}>
            <Page title={t('collect.page_title')} description={t('collect.page_desc')} width="wide">
                <FlashMessages />
                {sections.length > 6 && (
                    <div className="max-w-md">
                        <input
                            type="text"
                            value={filter}
                            onChange={(e) => setFilter(e.target.value)}
                            placeholder={t('collect.find_class_hint')}
                            autoComplete="off"
                            className="h-12 w-full rounded-lg border-stone-300 bg-white px-3 text-base text-stone-900 placeholder:text-stone-400 focus:border-brand-600 focus:ring-2 focus:ring-brand-600 dark:border-stone-600 dark:bg-stone-900 dark:text-stone-100"
                        />
                    </div>
                )}
                {shown.length === 0 ? (
                    <Card><EmptyState title={t('collect.no_classes_title')}>{t('collect.no_classes_body')}</EmptyState></Card>
                ) : (
                    <div className="space-y-3" data-testid="class-cards">
                        {shown.map((s) => (
                            <button
                                key={s.id}
                                type="button"
                                data-testid="class-card"
                                onClick={() => openSection(s.id)}
                                className="flex w-full items-center gap-4 rounded-2xl border-2 border-stone-200 bg-white p-5 text-left transition hover:border-brand-400 hover:shadow-md focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-200 dark:border-stone-700 dark:bg-stone-800"
                            >
                                <div className="min-w-0 flex-1">
                                    <h2 className="text-xl font-bold text-stone-900 dark:text-stone-100">{s.label}</h2>
                                    {branchCount > 1 && <p className="text-base text-stone-600 dark:text-stone-400">{s.branch}</p>}
                                </div>
                                <ChevronRightIcon className="h-6 w-6 shrink-0 text-stone-400" aria-hidden="true" />
                            </button>
                        ))}
                    </div>
                )}
            </Page>
        </AuthenticatedLayout>
    );
}
