import { Alert, BackLink, Button, Card, ChoiceCard, ChoiceGroup, Disclosure, EmptyState, Page, SuccessPanel, WizardShell, useConfirm, useWizard } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { ArrowUturnLeftIcon, CalendarDaysIcon, DocumentTextIcon } from '@heroicons/react/24/outline';
import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';

// Creating the school's bills, in three questions: WHO is billed (a grade), WHAT/WHEN (the whole year, or only some
// billing periods), then a final check that says exactly what will happen -- "Create bills for 32 students".
// The numbers on the last step come from a read-only preview run by the SAME code as the real run
// (InvoiceController@generatePreview), so they cannot drift from what is then created. Creating goes through the
// unchanged POST /invoices/generate: the once-per-period rule, the locks, the frozen lines, the notifications and
// the audit entry are all still the backend's. Choosing no periods = every period, exactly as before.

const money = (n) => Number(n).toLocaleString(undefined, { maximumFractionDigits: 2 });
const FREQ = { annual: 'student.freq_annual', termly: 'student.freq_termly', monthly: 'student.freq_monthly' };

function usePreview(branchId, scopeParams, periods, enabled) {
    const [state, setState] = useState({ status: 'idle', data: null });
    const key = JSON.stringify([branchId, scopeParams, periods]);

    useEffect(() => {
        if (!enabled || !branchId || !scopeParams) {
            setState({ status: 'idle', data: null });
            return undefined;
        }
        let cancelled = false;
        setState({ status: 'loading', data: null });
        fetch(route('invoices.generate.preview', { branch_id: branchId, ...scopeParams, ...(periods ? { periods } : {}) }), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then((r) => { if (!r.ok) throw new Error(String(r.status)); return r.json(); })
            .then((data) => { if (!cancelled) setState({ status: 'ready', data }); })
            .catch(() => { if (!cancelled) setState({ status: 'error', data: null }); });
        return () => { cancelled = true; };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [key, enabled]);

    return state;
}

/** Search-as-you-type for "Individual Student(s)" -- the same shape Discipline/Medical Records already
 *  use, extended to ADD several students to a list instead of navigating away on the first pick. */
function StudentPicker({ branchId, selected, onAdd, onRemove }) {
    const { t } = useTranslation();
    const [query, setQuery] = useState('');
    const [results, setResults] = useState([]);
    const [searching, setSearching] = useState(false);

    useEffect(() => {
        if (query.trim().length < 2) { setResults([]); return undefined; }
        setSearching(true);
        const handle = setTimeout(() => {
            fetch(route('invoices.student-search', { q: query.trim() }), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                .then((r) => (r.ok ? r.json() : []))
                .then(setResults)
                .finally(() => setSearching(false));
        }, 300);
        return () => clearTimeout(handle);
    }, [query]);

    const alreadyIn = (id) => selected.some((s) => s.id === id);

    return (
        <div className="space-y-4">
            <div>
                <p className="mb-2 text-lg font-semibold text-stone-900 dark:text-stone-100">{t('gen.student_search_label', 'Search for a student by name or ID')}</p>
                <input
                    type="text"
                    value={query}
                    onChange={(e) => setQuery(e.target.value)}
                    autoComplete="off"
                    placeholder={t('gen.student_search_placeholder', 'Type a name…')}
                    className="block w-full rounded-lg border-2 border-stone-300 px-4 py-3 text-base text-stone-900 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500 dark:border-stone-600 dark:bg-stone-800 dark:text-stone-100"
                />
                {query.trim().length >= 2 && (
                    <ul className="mt-2 divide-y divide-stone-200 rounded-xl border-2 border-stone-200 dark:divide-stone-700 dark:border-stone-700">
                        {searching && <li className="p-3 text-sm text-stone-600 dark:text-stone-400">{t('common.searching')}</li>}
                        {!searching && results.length === 0 && <li className="p-3 text-sm text-stone-600 dark:text-stone-400">{t('gen.no_students_found', 'No students found.')}</li>}
                        {results.filter((r) => String(r.branch_id) === String(branchId)).map((r) => (
                            <li key={r.id} className="flex items-center justify-between gap-3 p-3">
                                <span>
                                    <span className="block font-medium text-stone-900 dark:text-stone-100">{r.name}</span>
                                    <span className="block text-sm text-stone-600 dark:text-stone-400">{r.detail}</span>
                                </span>
                                <Button size="md" variant="secondary" disabled={alreadyIn(r.id)} onClick={() => { onAdd(r); setQuery(''); setResults([]); }}>
                                    {alreadyIn(r.id) ? t('gen.student_added', 'Added') : t('gen.student_add', 'Add')}
                                </Button>
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            {selected.length > 0 && (
                <div>
                    <p className="mb-2 text-sm font-semibold uppercase tracking-wide text-stone-600 dark:text-stone-400">{t('gen.students_chosen', 'Chosen students')}</p>
                    <ul className="space-y-2">
                        {selected.map((s) => (
                            <li key={s.id} className="flex items-center justify-between gap-3 rounded-xl border-2 border-brand-300 bg-brand-50 px-4 py-2.5 dark:border-brand-700 dark:bg-brand-900/20">
                                <span>
                                    <span className="block font-semibold text-stone-900 dark:text-stone-100">{s.name}</span>
                                    <span className="block text-sm text-stone-600 dark:text-stone-400">{s.detail}</span>
                                </span>
                                <Button size="md" variant="quiet" onClick={() => onRemove(s.id)}>{t('common.remove', 'Remove')}</Button>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </div>
    );
}

function useLabels() {
    const { t } = useTranslation();
    return (p) => (p.frequency === 'annual' ? t('gen.period_annual') : p.label);
}

function SkippedList({ skipped }) {
    const { t } = useTranslation();
    const rows = ['already_billed', 'left_school', 'nothing_to_bill'].filter((k) => skipped[k].count > 0);
    if (rows.length === 0) return null;

    return (
        <div className="space-y-3" data-testid="skipped">
            {rows.map((k) => (
                <div key={k}>
                    <p className="text-base font-semibold text-stone-900 dark:text-stone-100">{t(`gen.skip_${k}`, { count: skipped[k].count })}</p>
                    <p className="text-base text-stone-700 dark:text-stone-300">{t(`gen.skip_${k}_why`)}</p>
                    <details className="mt-1">
                        <summary className="min-h-[44px] cursor-pointer py-2 text-base font-medium text-brand-800 dark:text-brand-300">{t('gen.skip_names')}</summary>
                        <ul className="list-disc pl-6 text-base text-stone-800 dark:text-stone-200">
                            {skipped[k].names.map((n, i) => <li key={i}>{n}</li>)}
                            {skipped[k].count > skipped[k].names.length && <li>{t('gen.skip_more', { n: skipped[k].count - skipped[k].names.length })}</li>}
                        </ul>
                    </details>
                </div>
            ))}
        </div>
    );
}

function ResultScreen({ run, onAnother }) {
    const { t } = useTranslation();
    const label = useLabels();
    const none = run.invoices === 0;
    const [ask, confirmDialog] = useConfirm();
    const [cancelling, setCancelling] = useState(false);

    // Lands on the invoices list, not back on this screen: the now-cancelled (waived) bills are right
    // there, which is its own confirmation -- a normal Inertia navigation, same as every other button here.
    const cancelBatch = async () => {
        const ok = await ask({
            title: t('gen.undo_title', { count: run.invoices }),
            body: <p>{t('gen.undo_body')}</p>,
            confirmLabel: t('gen.undo_button'),
        });
        if (!ok) return;
        setCancelling(true);
        router.post(route('invoices.batches.cancel', run.batch_id), {}, { onFinish: () => setCancelling(false) });
    };

    return (
        <Page title={t('gen.title')} width="default">
            <SuccessPanel
                title={none ? t('gen.result_none_title') : t('gen.result_title')}
                details={
                    <div className="space-y-4">
                        {!none && (
                            <ul className="space-y-1 rounded-xl bg-white/70 p-4 text-base text-emerald-950 dark:bg-stone-900/40 dark:text-emerald-50" data-testid="result-periods">
                                {run.periods.filter((p) => p.created > 0).map((p) => <li key={p.key}>• {label(p)}: {t('gen.bills_count', { count: p.created })}</li>)}
                                <li className="pt-1 font-semibold">{t('gen.total_amount', { amount: money(run.amount) })}</li>
                                {run.parents_notified > 0 && <li>{t('gen.parents_told', { count: run.parents_notified })}</li>}
                            </ul>
                        )}
                        {none && <p className="text-base text-emerald-950 dark:text-emerald-50">{t('gen.result_none_body')}</p>}
                        {run.students - run.students_billed > 0 && (
                            <Alert tone="info" title={t('gen.skipped_title', { count: run.students - run.students_billed })}>
                                <SkippedList skipped={run.skipped} />
                            </Alert>
                        )}
                    </div>
                }
                actions={
                    <div className="flex w-full flex-col gap-3">
                        <Button as={Link} href={route('invoices.index', { grade_id: run.grade_id })} fullWidth icon={DocumentTextIcon}>{t('gen.see_bills')}</Button>
                        <Button variant="secondary" fullWidth onClick={onAnother}>{t('gen.another')}</Button>
                        {!none && run.batch_id && (
                            <Button variant="quiet" fullWidth icon={ArrowUturnLeftIcon} onClick={cancelBatch} disabled={cancelling} data-testid="cancel-batch">
                                {t('gen.undo')}
                            </Button>
                        )}
                        <Button as={Link} href={route('invoices.index')} variant="quiet" fullWidth>{t('gen.back_to_invoices')}</Button>
                    </div>
                }
            >
                {none ? `${run.grade} · ${run.branch}` : t('gen.result_body', { invoices: run.invoices, students: run.students_billed, grade: run.grade })}
            </SuccessPanel>
            {confirmDialog}
        </Page>
    );
}

export default function Generate({ branches, grades, academicYear, studentCounts, allClassesCounts, run }) {
    const { t } = useTranslation();
    const { errors } = usePage().props;
    const label = useLabels();

    const [branchId, setBranchId] = useState(branches.length === 1 ? String(branches[0].id) : '');
    // '' | 'all' | 'grades' | 'students' -- who the bills are for. 'grades' covers both "one class" (the
    // original single-grade workflow) and "several specific classes": the same multi-select either way.
    const [scopeType, setScopeType] = useState('');
    const [gradeIds, setGradeIds] = useState([]);
    const [students, setStudents] = useState([]); // [{id, name, detail}]
    const [mode, setMode] = useState('');               // '' | 'year' | 'some'
    const [periods, setPeriods] = useState([]);
    const [processing, setProcessing] = useState(false);
    const [dismissed, setDismissed] = useState(false);

    const branch = branches.find((b) => String(b.id) === String(branchId));
    const countOf = (g) => studentCounts?.[`${branchId}-${g.id}`] ?? 0;
    const toggleGrade = (id) => setGradeIds((ids) => (ids.includes(id) ? ids.filter((x) => x !== id) : [...ids, id]));
    const addStudent = (s) => setStudents((list) => (list.some((x) => x.id === s.id) ? list : [...list, s]));
    const removeStudent = (id) => setStudents((list) => list.filter((s) => s.id !== id));

    // What is sent to the preview/generate endpoints, built from whichever targeting mode is active.
    // `null` while incomplete so usePreview() knows not to fetch yet.
    const scopeParams = scopeType === 'all' ? { scope_type: 'all' }
        : scopeType === 'grades' && gradeIds.length > 0 ? { scope_type: 'grades', grade_ids: gradeIds }
            : scopeType === 'students' && students.length > 0 ? { scope_type: 'students', student_ids: students.map((s) => s.id) }
                : null;
    const scopeReady = Boolean(scopeParams);

    const all = usePreview(branchId, scopeParams, null, Boolean(academicYear));
    const chosen = mode === 'some' ? periods : null;
    const sel = usePreview(branchId, scopeParams, chosen, Boolean(academicYear) && mode === 'some' && periods.length > 0);
    const final = mode === 'year' ? all : sel;
    const allData = all.status === 'ready' ? all.data : null;
    const finalResult = final.status === 'ready' ? final.data.result : null;
    const toggle = (key) => setPeriods((p) => (p.includes(key) ? p.filter((k) => k !== key) : [...p, key]));

    const steps = [
        { key: 'who', label: t('gen.step_who'), description: t('gen.step_who_desc'), fields: ['branch_id', 'scope_type'], isValid: () => Boolean(branchId && scopeReady), whyBlocked: t('gen.why_who') },
        {
            key: 'what', label: t('gen.step_what', { grade: allData?.grade ?? '' }), description: t('gen.step_what_desc'), fields: ['periods', 'generate'],
            isValid: () => all.status === 'ready' && !allData?.blocked && (mode === 'year' || (mode === 'some' && periods.length > 0)),
            whyBlocked: allData?.blocked ? t('gen.why_blocked') : mode === '' ? t('gen.why_mode') : t('gen.why_periods'),
        },
        { key: 'check', label: t('gen.step_check'), description: t('gen.step_check_desc'), isValid: () => Boolean(finalResult && finalResult.invoices > 0), whyBlocked: t('gen.why_nothing') },
    ];

    const wizard = useWizard({
        steps,
        errors,
        onSubmit: () => {
            setProcessing(true);
            router.post(route('invoices.generate'), { branch_id: branchId, ...scopeParams, ...(mode === 'some' ? { periods } : {}) }, { onFinish: () => setProcessing(false) });
        },
    });
    const key = wizard.step.key;

    const another = () => { setDismissed(true); setScopeType(''); setGradeIds([]); setStudents([]); setMode(''); setPeriods([]); wizard.reset(); };
    useEffect(() => { if (run) setDismissed(false); }, [run]);

    const layoutHeader = <h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">{t('gen.title')}</h2>;

    if (run && !dismissed) {
        return <AuthenticatedLayout header={layoutHeader}><ResultScreen run={run} onAnother={another} /></AuthenticatedLayout>;
    }

    if (!academicYear) {
        return (
            <AuthenticatedLayout header={layoutHeader}>
                <Page title={t('gen.title')} width="default" back={<BackLink href={route('invoices.index')}>{t('gen.back_to_invoices')}</BackLink>}>
                    <Card><EmptyState icon={CalendarDaysIcon} title={t('gen.no_year_title')} action={<Button as={Link} href={route('academic-years.index')}>{t('gen.open_years')}</Button>}>{t('gen.no_year_body')}</EmptyState></Card>
                </Page>
            </AuthenticatedLayout>
        );
    }

    const blockedAlert = allData?.blocked && (
        <Alert
            tone={allData.blocked === 'nothing_new' ? 'info' : 'warning'}
            title={t(`gen.blocked_${allData.blocked}_title`)}
            action={
                allData.blocked === 'no_fees' ? <Button as={Link} href={route('fee-structures.index')} variant="secondary">{t('gen.open_fees')}</Button>
                    : allData.blocked === 'no_terms' ? <Button as={Link} href={route('academic-years.index')} variant="secondary">{t('gen.open_years')}</Button>
                        : allData.blocked === 'nothing_new' ? <Button as={Link} href={route('invoices.index', scopeType === 'grades' && gradeIds.length === 1 ? { grade_id: gradeIds[0] } : {})} variant="secondary">{t('gen.see_bills')}</Button> : null
            }
        >
            <span data-testid="blocked">{t(`gen.blocked_${allData.blocked}_body`, { grade: allData?.grade, branch: branch?.name })}</span>
        </Alert>
    );

    return (
        <AuthenticatedLayout header={layoutHeader}>
            <Page title={t('gen.title')} description={t('gen.desc', { year: academicYear })} width="default" back={<BackLink href={route('invoices.index')}>{t('gen.back_to_invoices')}</BackLink>}>
                {errors.generate && <Alert tone="danger" title={t('gen.error_title')}>{errors.generate}</Alert>}
                {errors.periods && <Alert tone="danger" title={t('gen.error_title')}>{errors.periods}</Alert>}

                <Card>
                    <WizardShell wizard={wizard} finalLabel={t('gen.create_button', { count: finalResult?.students_billed ?? 0 })} processing={processing}>
                        {key === 'who' && (
                            <div className="space-y-6">
                                {branches.length > 1 && (
                                    <ChoiceGroup
                                        label={t('gen.branch')}
                                        value={branchId}
                                        onChange={(v) => { setBranchId(v); setScopeType(''); setGradeIds([]); setStudents([]); setMode(''); setPeriods([]); }}
                                        options={branches.map((b) => ({ value: String(b.id), label: b.name }))}
                                    />
                                )}
                                {branchId && (
                                    <div>
                                        <p className="mb-2 text-lg font-semibold text-stone-900 dark:text-stone-100">{t('gen.who_label')}</p>
                                        <ChoiceGroup
                                            label={t('gen.who_label')}
                                            value={scopeType}
                                            onChange={(v) => { setScopeType(v); setGradeIds([]); setStudents([]); setMode(''); setPeriods([]); }}
                                            options={[
                                                { value: 'all', label: t('gen.scope_all'), hint: t('gen.scope_all_hint', { count: allClassesCounts?.[branchId] ?? 0 }) },
                                                { value: 'grades', label: t('gen.scope_grades'), hint: t('gen.scope_grades_hint') },
                                                { value: 'students', label: t('gen.scope_students'), hint: t('gen.scope_students_hint') },
                                            ]}
                                        />
                                    </div>
                                )}
                                {branchId && scopeType === 'grades' && (
                                    <div>
                                        <p className="mb-2 text-lg font-semibold text-stone-900 dark:text-stone-100">{t('gen.grade')}</p>
                                        <div role="group" aria-label={t('gen.grade')} className="grid grid-cols-2 gap-3 sm:grid-cols-3" data-testid="grade-grid">
                                            {grades.map((g) => {
                                                const n = countOf(g);
                                                const selected = gradeIds.includes(g.id);
                                                return (
                                                    <button
                                                        key={g.id}
                                                        type="button"
                                                        role="checkbox"
                                                        aria-checked={selected}
                                                        disabled={n === 0}
                                                        onClick={() => { toggleGrade(g.id); setMode(''); setPeriods([]); }}
                                                        className={`flex min-h-[72px] flex-col items-center justify-center rounded-xl border-2 px-3 py-2 text-center transition focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 ${selected ? 'border-brand-600 bg-brand-600 text-white' : 'border-stone-300 bg-white text-stone-900 hover:border-brand-400 dark:border-stone-600 dark:bg-stone-800 dark:text-stone-100'} ${n === 0 ? 'cursor-not-allowed opacity-50' : ''}`}
                                                    >
                                                        <span className="text-lg font-bold">{g.name}</span>
                                                        <span className={`text-sm ${selected ? 'text-brand-100' : 'text-stone-600 dark:text-stone-400'}`}>{n === 0 ? t('gen.no_students') : t('gen.students_count', { count: n })}</span>
                                                    </button>
                                                );
                                            })}
                                        </div>
                                        <p className="mt-3 text-sm text-stone-600 dark:text-stone-400">{t('gen.grade_note')}</p>
                                    </div>
                                )}
                                {branchId && scopeType === 'students' && (
                                    <StudentPicker branchId={branchId} selected={students} onAdd={(s) => { addStudent(s); setMode(''); setPeriods([]); }} onRemove={(id) => { removeStudent(id); setMode(''); setPeriods([]); }} />
                                )}
                            </div>
                        )}

                        {key === 'what' && (
                            <div className="space-y-5">
                                {all.status === 'loading' && <p className="text-base text-stone-700 dark:text-stone-300" role="status">{t('gen.loading')}</p>}
                                {all.status === 'error' && <Alert tone="danger" title={t('gen.error_title')}>{t('gen.preview_error')}</Alert>}
                                {allData && (
                                    <>
                                        <div className="rounded-xl bg-stone-50 p-4 dark:bg-stone-900/40" data-testid="fees-card">
                                            <p className="text-lg font-semibold text-stone-900 dark:text-stone-100">{t('gen.fees_title', { grade: allData.grade, count: allData.students })}</p>
                                            {allData.fees.length > 0 ? (
                                                <ul className="mt-2 space-y-1 text-base text-stone-800 dark:text-stone-200">
                                                    {allData.fees.map((f, i) => <li key={i} className="flex justify-between gap-3"><span>{f.name}{f.grade ? ` (${f.grade})` : ''}</span><span>{money(f.amount)} ETB {t(FREQ[f.frequency] ?? FREQ.annual)}</span></li>)}
                                                </ul>
                                            ) : <p className="mt-1 text-base text-stone-700 dark:text-stone-300">{t('gen.no_fees_listed')}</p>}
                                            {allData.services_students > 0 && <p className="mt-2 text-base text-stone-700 dark:text-stone-300">{t('gen.services_note', { count: allData.services_students })}</p>}
                                        </div>
                                        {blockedAlert}
                                        {!allData.blocked && (
                                            <>
                                                <ChoiceGroup
                                                    label={t('gen.mode_label')}
                                                    value={mode}
                                                    onChange={setMode}
                                                    options={[
                                                        { value: 'year', label: t('gen.mode_year'), hint: t('gen.mode_year_hint', { count: allData.result.invoices }) },
                                                        { value: 'some', label: t('gen.mode_some'), hint: t('gen.mode_some_hint') },
                                                    ]}
                                                />
                                                {mode === 'some' && (
                                                    <div className="space-y-4" data-testid="period-picker">
                                                        {[['annual', t('gen.group_annual')], ['termly', t('gen.group_termly')], ['monthly', t('gen.group_monthly')]].map(([freq, title]) => {
                                                            const list = allData.result.periods.filter((p) => p.frequency === freq);
                                                            if (list.length === 0) return null;
                                                            const cards = list.map((p) => {
                                                                const done = p.created === 0;
                                                                return (
                                                                    <ChoiceCard
                                                                        key={p.key}
                                                                        role="checkbox"
                                                                        icon={CalendarDaysIcon}
                                                                        title={label(p)}
                                                                        hint={done ? t('gen.period_done') : `${t('gen.bills_count', { count: p.created })} · ${money(p.amount)} ETB`}
                                                                        selected={periods.includes(p.key)}
                                                                        disabled={done}
                                                                        onSelect={() => toggle(p.key)}
                                                                    />
                                                                );
                                                            });
                                                            return freq === 'monthly' ? (
                                                                <Disclosure key={freq} title={title} description={t('gen.months_desc', { count: list.length })} defaultOpen={periods.some((k) => k.startsWith('month:'))}>
                                                                    <div className="space-y-3">{cards}</div>
                                                                </Disclosure>
                                                            ) : (
                                                                <div key={freq}><p className="mb-2 text-lg font-semibold text-stone-900 dark:text-stone-100">{title}</p><div role="group" aria-label={title} className="space-y-3">{cards}</div></div>
                                                            );
                                                        })}
                                                    </div>
                                                )}
                                            </>
                                        )}
                                    </>
                                )}
                            </div>
                        )}

                        {key === 'check' && (
                            <div className="space-y-4">
                                {final.status === 'loading' && <p className="text-base text-stone-700 dark:text-stone-300" role="status">{t('gen.loading')}</p>}
                                {final.status === 'error' && <Alert tone="danger" title={t('gen.error_title')}>{t('gen.preview_error')}</Alert>}
                                {final.status === 'ready' && final.data.blocked === 'nothing_new' && <Alert tone="info" title={t('gen.blocked_nothing_new_title')}>{t('gen.blocked_nothing_new_body')}</Alert>}
                                {finalResult && (
                                    <>
                                        <div className="rounded-2xl border-2 border-brand-300 bg-brand-50 p-5 dark:border-brand-700 dark:bg-brand-900/20" data-testid="headline">
                                            <p className="text-2xl font-bold text-stone-900 dark:text-stone-100">{t('gen.create_button', { count: finalResult.students_billed })}</p>
                                            <p className="mt-1 text-lg text-stone-800 dark:text-stone-200">{t('gen.bills_count', { count: finalResult.invoices })} · {t('gen.total_amount', { amount: money(finalResult.amount) })}</p>
                                        </div>
                                        <dl className="divide-y divide-stone-200 dark:divide-stone-700" data-testid="check-details">
                                            <div className="py-3"><dt className="text-sm font-semibold uppercase tracking-wide text-stone-600 dark:text-stone-400">{t('gen.check_for')}</dt><dd className="text-lg text-stone-900 dark:text-stone-100">{final.data.grade} · {final.data.branch} · {final.data.academic_year}</dd></div>
                                            <div className="py-3">
                                                <dt className="text-sm font-semibold uppercase tracking-wide text-stone-600 dark:text-stone-400">{t('gen.check_periods')}</dt>
                                                <dd className="text-lg text-stone-900 dark:text-stone-100">
                                                    <ul>{finalResult.periods.filter((p) => p.created > 0).map((p) => <li key={p.key}>{label(p)} — {t('gen.bills_count', { count: p.created })}</li>)}</ul>
                                                </dd>
                                            </div>
                                            <div className="py-3"><dt className="text-sm font-semibold uppercase tracking-wide text-stone-600 dark:text-stone-400">{t('gen.check_parents')}</dt><dd className="text-lg text-stone-900 dark:text-stone-100">{finalResult.parents_notified > 0 ? t('gen.parents_will', { count: finalResult.parents_notified }) : t('gen.parents_none')}</dd></div>
                                        </dl>
                                        {finalResult.future_invoices > 0 && <Alert tone="warning" title={t('gen.warn_future_title')}><span data-testid="warn-future">{t('gen.warn_future_body', { count: finalResult.future_invoices })}</span></Alert>}
                                        {finalResult.students - finalResult.students_billed > 0 && (
                                            <Alert tone="info" title={t('gen.skipped_title', { count: finalResult.students - finalResult.students_billed })}>
                                                <SkippedList skipped={finalResult.skipped} />
                                            </Alert>
                                        )}
                                        <p className="text-base text-stone-700 dark:text-stone-300">{t('gen.final_note')}</p>
                                    </>
                                )}
                            </div>
                        )}
                    </WizardShell>
                </Card>
            </Page>
        </AuthenticatedLayout>
    );
}
