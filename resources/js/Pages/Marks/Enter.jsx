import { Alert, BackLink, Button, Card, Disclosure, EmptyState, Field, Input, Page, StatusBadge, SuccessPanel, useConfirm } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useUnsavedGuard } from '@/useUnsavedGuard';
import { CheckCircleIcon, MagnifyingGlassIcon, UsersIcon, XMarkIcon } from '@heroicons/react/24/outline';
import { Link, router, usePage } from '@inertiajs/react';
import { useMemo, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';

// Entering marks: every student in the class on one page, a score box right next to each name, a
// search box to jump straight to one name in a big class -- no "next student" step to click through.
// The academic workflow is unchanged (draft -> submitted -> approved -> published; who may enter; the
// server's validation); only the screen changed. One rule is deliberately kept:
//  - EVERY save sends the whole class list (blank = no mark), exactly as before. A blank mark is a row
//    with no score, and report cards treat "no score" (the school's missing-mark policy applies)
//    differently from "no row at all" (not applicable) -- so an unreached student still gets their row.
//  - Marks stay editable only while the batch is a draft; after that the page is read-only and says
//    where the marks are in the process and what happens next.

const SCORE = /^\d{1,3}(\.\d{1,2})?$/;               // what the database column holds: up to 999.99
const norm = (s) => String(s ?? '').trim().replace(',', '.');
const canon = (s) => { const v = norm(s); return v !== '' && SCORE.test(v) ? String(Number(v)) : v; };
const fmt = (v) => (v === null || v === undefined || v === '' ? '' : String(Number(v)));

export default function Enter({ batch, exam, section, subject, roster, missing_mark_policy, readOnly = false, backTo }) {
    const { t } = useTranslation();
    const { errors } = usePage().props;
    const [ask, confirmDialog] = useConfirm();
    const isDraft = batch.status === 'draft' && !readOnly;
    const listRef = useRef(null);

    const saved = useMemo(() => Object.fromEntries(roster.map((r) => [r.enrollment_id, fmt(r.score)])), [roster]);
    const [scores, setScores] = useState(saved);
    const [maxInput, setMaxInput] = useState(fmt(batch.max_score));
    const [search, setSearch] = useState('');
    const [saving, setSaving] = useState(false);
    const [notice, setNotice] = useState(null);
    const [justSubmitted, setJustSubmitted] = useState(false);

    const total = roster.length;
    const maxOk = SCORE.test(norm(maxInput)) && Number(norm(maxInput)) >= 1;
    const max = maxOk ? Number(norm(maxInput)) : Number(batch.max_score);
    const valueOf = (r) => canon(scores[r.enrollment_id]);
    const problemOf = (r) => {
        const v = valueOf(r);
        if (v === '') return null;
        if (!SCORE.test(v)) return 'format';
        return maxOk && Number(v) > max ? 'max' : null;
    };
    const isChanged = (r) => valueOf(r) !== saved[r.enrollment_id];
    const changed = roster.filter(isChanged);
    const invalid = roster.filter((r) => problemOf(r));
    const maxChanged = maxOk && Number(norm(maxInput)) !== Number(batch.max_score);
    const dirty = isDraft && (changed.length > 0 || maxChanged);
    const blocked = !maxOk || invalid.length > 0;
    const entered = roster.filter((r) => valueOf(r) !== '').length;
    const shownMax = fmt(max);

    const needle = search.trim().toLowerCase();
    const visible = needle === '' ? roster : roster.filter((r) => r.student_name.toLowerCase().includes(needle) || r.student_id_number?.toLowerCase().includes(needle));

    useUnsavedGuard(dirty, () => ask({
        title: t('marks.leave_title'),
        body: <p>{t('marks.leave_body')}</p>,
        confirmLabel: t('marks.leave_leave'),
        cancelLabel: t('marks.leave_stay'),
        tone: 'danger',
    }));

    const title = t('marks.enter_title', { subject: subject.name });

    // ------------------------------------------------------------------ saving (always the whole class list)
    const save = (then) => {
        if (blocked) return;
        setSaving(true);
        router.post(
            route('mark-batches.store', batch.id),
            { records: roster.map((r) => ({ enrollment_id: r.enrollment_id, score: valueOf(r) === '' ? null : valueOf(r), max_score: max })) },
            { preserveScroll: true, onSuccess: () => then?.(), onFinish: () => setSaving(false) }
        );
    };

    const saveAll = () => save(() => setNotice(t('marks.saved_note_all')));

    const focusNext = (enrollmentId) => {
        const root = listRef.current;
        if (!root) return;
        const inputs = Array.from(root.querySelectorAll('input[data-mark-input]'));
        const idx = inputs.findIndex((el) => el.dataset.enrollmentId === String(enrollmentId));
        const nextEl = inputs[idx + 1] ?? inputs[0];
        nextEl?.focus();
        nextEl?.select?.();
    };

    const submit = async () => {
        const blanks = roster.filter((r) => valueOf(r) === '');
        const consequences = [
            <li key="lock">{t('marks.submit_lock')}</li>,
            <li key="flow">{t('marks.submit_flow')}</li>,
        ];
        if (blanks.length > 0) {
            consequences.unshift(
                <li key="blank" className="font-medium">
                    {t('marks.submit_blank', { count: blanks.length })} {t(`marks.policy_${missing_mark_policy === 'zero' ? 'zero' : 'exclude'}`)}
                </li>
            );
        }
        const ok = await ask({
            title: t('marks.submit_title'),
            body: <ul className="list-inside list-disc space-y-2">{consequences}</ul>,
            confirmLabel: t('marks.submit_yes'),
            cancelLabel: t('marks.submit_back'),
            tone: 'info',
        });
        if (!ok) return;
        const send = () => router.post(route('mark-batches.submit', batch.id), {}, { onSuccess: () => setJustSubmitted(true) });
        if (dirty) save(send);
        else send();
    };

    const serverError = errors && Object.keys(errors).length > 0 ? Object.values(errors)[0] : null;
    const problemText = (r) => {
        const p = problemOf(r);
        return p === 'format' ? t('marks.err_format') : p === 'max' ? t('marks.err_max', { max: shownMax }) : null;
    };
    const why = !maxOk ? t('marks.err_max_field') : invalid.length > 0 ? t('marks.why_other_invalid', { name: invalid[0].student_name }) : null;
    const facts = (
        <dl className="flex flex-wrap gap-x-6 gap-y-1" data-testid="facts">
            {[[t('marks.fact_class'), section.label], [t('marks.fact_subject'), subject.name], [t('marks.fact_exam'), exam.name], [t('marks.fact_max'), shownMax]].map(([k, v]) => (
                <div key={k} className="flex items-baseline gap-1.5">
                    <dt className="text-sm text-stone-600 dark:text-stone-400">{k}</dt>
                    <dd className="text-base font-semibold text-stone-900 dark:text-stone-100">{v}</dd>
                </div>
            ))}
        </dl>
    );
    const layoutHeader = <h2 className="truncate text-lg font-semibold text-stone-800 dark:text-stone-200">{section.label} — {subject.name} — {exam.name}</h2>;
    const back = backTo
        ? <BackLink href={route(backTo.route, backTo.params)}>{t('marks.back_to_approvals')}</BackLink>
        : <BackLink href={route('marks.index')}>{t('marks.back_to_marks')}</BackLink>;

    // ------------------------------------------------------------------ locked: submitted / approved / published
    if (!isDraft) {
        return (
            <AuthenticatedLayout header={layoutHeader}>
                <Page title={title} width="narrow" back={back}>
                    {justSubmitted && (
                        <SuccessPanel title={t('marks.submitted_title')} actions={<Button as={Link} href={route('marks.index')}>{t('marks.back_to_marks')}</Button>}>
                            {t('marks.submitted_body')}
                        </SuccessPanel>
                    )}
                    <Card>
                        <div className="mb-4 flex flex-wrap items-center gap-3">
                            <StatusBadge status={batch.status} />
                            <p className="text-base font-medium text-stone-900 dark:text-stone-100">{t(`marks.state_${batch.status}`)}</p>
                        </div>
                        <p className="mb-4 text-base text-stone-700 dark:text-stone-300">{t(`marks.state_${batch.status}_next`)}</p>
                        {facts}
                    </Card>
                    <Card>
                        <ul className="divide-y divide-stone-200 dark:divide-stone-700" data-testid="readonly-list">
                            {roster.map((r) => (
                                <li key={r.enrollment_id} className="flex items-center justify-between gap-3 py-3">
                                    <span className="text-lg text-stone-900 dark:text-stone-100">{r.student_name}</span>
                                    <span className="text-lg font-semibold text-stone-900 dark:text-stone-100">{saved[r.enrollment_id] === '' ? <span className="font-normal text-stone-600 dark:text-stone-400">{t('marks.list_no_mark')}</span> : `${saved[r.enrollment_id]} / ${shownMax}`}</span>
                                </li>
                            ))}
                        </ul>
                        {roster.length === 0 && <EmptyState icon={UsersIcon} title={t('marks.empty_roster')} />}
                    </Card>
                </Page>
                {confirmDialog}
            </AuthenticatedLayout>
        );
    }

    // ------------------------------------------------------------------ the maximum mark (one for the whole list)
    const maxControl = (
        <Disclosure title={t('marks.max_title')} description={t('marks.max_desc', { max: shownMax })} defaultOpen={!maxOk}>
            <Field id="max-input" label={t('marks.max_label')} hint={t('marks.max_warn')} error={maxOk ? null : t('marks.err_max_field')}>
                <Input inputMode="decimal" autoComplete="off" value={maxInput} onChange={(e) => setMaxInput(e.target.value)} />
            </Field>
        </Disclosure>
    );

    // ------------------------------------------------------------------ marking: everyone, on one page
    return (
        <AuthenticatedLayout header={layoutHeader}>
            <Page title={title} width="default" back={back}>
                {serverError && <Alert tone="danger" title={t('marks.error_title')}>{serverError}</Alert>}
                <Card>{facts}</Card>

                {total === 0 ? (
                    <Card><EmptyState icon={UsersIcon} title={t('marks.empty_roster')} /></Card>
                ) : (
                    <>
                        <div aria-live="polite">
                            {notice && <Alert tone="success" onDismiss={() => setNotice(null)}><span data-testid="saved-notice">{notice}</span></Alert>}
                        </div>

                        <Card>
                            <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                                <label htmlFor="student-search" className="sr-only">{t('marks.search_label')}</label>
                                <div className="relative w-full sm:max-w-sm">
                                    <MagnifyingGlassIcon className="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-stone-400" aria-hidden="true" />
                                    <input
                                        id="student-search"
                                        type="text"
                                        value={search}
                                        onChange={(e) => setSearch(e.target.value)}
                                        placeholder={t('marks.search_placeholder')}
                                        autoComplete="off"
                                        className="block min-h-[48px] w-full rounded-lg border-stone-300 bg-white pl-10 pr-10 text-base text-stone-900 placeholder:text-stone-400 focus:border-brand-600 focus:ring-2 focus:ring-brand-600 dark:border-stone-600 dark:bg-stone-900 dark:text-stone-100"
                                    />
                                    {search !== '' && (
                                        <button
                                            type="button"
                                            aria-label={t('marks.search_clear')}
                                            onClick={() => setSearch('')}
                                            className="absolute right-2 top-1/2 flex h-8 w-8 -translate-y-1/2 items-center justify-center rounded-full text-stone-500 hover:bg-stone-100 dark:text-stone-400 dark:hover:bg-stone-700"
                                        >
                                            <XMarkIcon className="h-5 w-5" aria-hidden="true" />
                                        </button>
                                    )}
                                </div>
                                <p className="whitespace-nowrap text-base font-semibold text-stone-900 dark:text-stone-100" data-testid="entered-progress">{t('marks.entered_progress', { entered, count: total })}</p>
                            </div>
                        </Card>

                        <Card>
                            {visible.length === 0 ? (
                                <p className="py-6 text-center text-base text-stone-600 dark:text-stone-400" data-testid="search-empty">{t('marks.search_none', { query: search })}</p>
                            ) : (
                                <ul ref={listRef} className="divide-y divide-stone-200 dark:divide-stone-700" data-testid="student-list">
                                    {visible.map((r) => {
                                        const v = valueOf(r);
                                        const p = problemOf(r);
                                        const state = p ? 'invalid' : isChanged(r) ? 'unsaved' : v === '' ? 'blank' : 'saved';
                                        return (
                                            <li key={r.enrollment_id} className="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between sm:gap-4" data-testid="student-row" data-state={state}>
                                                <div className="min-w-0">
                                                    <p className="truncate text-lg font-semibold text-stone-900 dark:text-stone-100" data-testid="student-name">{r.student_name}</p>
                                                    <p className="text-sm text-stone-600 dark:text-stone-400">{r.student_id_number}</p>
                                                </div>
                                                <div className="flex items-center gap-3">
                                                    <div className="flex items-center gap-2">
                                                        <input
                                                            type="text"
                                                            inputMode="decimal"
                                                            autoComplete="off"
                                                            data-mark-input
                                                            data-enrollment-id={r.enrollment_id}
                                                            aria-label={t('marks.row_input_label', { name: r.student_name, max: shownMax })}
                                                            aria-invalid={p ? true : undefined}
                                                            value={scores[r.enrollment_id] ?? ''}
                                                            onChange={(e) => setScores((s) => ({ ...s, [r.enrollment_id]: e.target.value }))}
                                                            onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); focusNext(r.enrollment_id); } }}
                                                            className={`h-14 w-24 rounded-xl border-2 bg-white text-center text-2xl font-bold text-stone-900 shadow-sm focus:ring-4 dark:bg-stone-900 dark:text-stone-100 ${p ? 'border-red-600 focus:border-red-600 focus:ring-red-200' : 'border-stone-400 focus:border-brand-600 focus:ring-brand-200 dark:border-stone-500'}`}
                                                        />
                                                        <span className="whitespace-nowrap text-lg font-semibold text-stone-600 dark:text-stone-300">/ {shownMax}</span>
                                                    </div>
                                                    <span className="w-28 shrink-0 text-right text-sm font-semibold">
                                                        {state === 'invalid' && <span className="text-red-700 dark:text-red-300">{problemText(r)}</span>}
                                                        {state === 'unsaved' && <span className="text-amber-900 dark:text-amber-300">{t('marks.list_unsaved')}</span>}
                                                        {state === 'saved' && <span className="inline-flex items-center gap-1 text-emerald-800 dark:text-emerald-300"><CheckCircleIcon className="h-4 w-4" aria-hidden="true" />{t('marks.list_saved')}</span>}
                                                        {state === 'blank' && <span className="font-normal text-stone-500 dark:text-stone-400">{t('marks.list_no_mark')}</span>}
                                                    </span>
                                                </div>
                                            </li>
                                        );
                                    })}
                                </ul>
                            )}
                            <p className="mt-3 text-sm text-stone-600 dark:text-stone-400">{t('marks.row_hint')}</p>

                            <div className="sticky bottom-0 z-10 -mx-5 -mb-5 mt-5 border-t-2 border-stone-200 bg-white/95 px-5 py-4 backdrop-blur dark:border-stone-700 dark:bg-stone-800/95 sm:static sm:mx-0 sm:mb-0 sm:border-0 sm:bg-transparent sm:p-0 sm:backdrop-blur-none">
                                <Button fullWidth variant={dirty ? 'primary' : 'secondary'} busy={saving} disabled={blocked || !dirty} onClick={saveAll} className="!min-h-[64px] text-xl" data-testid="save-marks">{t('marks.btn_save_only')}</Button>
                                {blocked && why && <p className="mt-2 text-sm font-medium text-stone-700 dark:text-stone-300" role="status">{why}</p>}
                                {!blocked && dirty && <p className="mt-2 text-sm font-medium text-amber-900 dark:text-amber-300" data-testid="unsaved-hint">{t('marks.unsaved_hint', { count: changed.length })}</p>}
                            </div>
                        </Card>

                        {maxControl}

                        <Card>
                            <Button fullWidth variant="secondary" onClick={submit} disabled={blocked || entered === 0}>{t('marks.btn_submit')}</Button>
                            {entered === 0 && <p className="mt-2 text-sm text-stone-600 dark:text-stone-400">{t('marks.why_submit_none')}</p>}
                        </Card>
                    </>
                )}
            </Page>
            {confirmDialog}
        </AuthenticatedLayout>
    );
}
