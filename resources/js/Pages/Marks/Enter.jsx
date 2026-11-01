import { Alert, BackLink, Button, Card, ChoiceGroup, Disclosure, EmptyState, Field, Input, Page, StatusBadge, SuccessPanel, useConfirm } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useUnsavedGuard } from '@/useUnsavedGuard';
import { ArrowLeftIcon, ArrowRightIcon, CheckCircleIcon, ExclamationTriangleIcon, UsersIcon } from '@heroicons/react/24/outline';
import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';

// Entering marks, one student at a time: who -> the mark -> "Save & Next".
//
// The academic workflow is exactly what it was (draft -> submitted -> approved -> published; who may enter; the
// server's validation). What changed is the screen, and one rule is deliberately kept:
//  - EVERY save sends the whole class list (blank = no mark), exactly as the old table did. A blank mark is a row
//    with no score, and report cards treat "no score" (the school's missing-mark policy applies) differently from
//    "no row at all" (not applicable) -- so a student the teacher has not reached yet must still get their row.
//  - Marks stay editable only while the batch is a draft; after that the page is read-only and says where the
//    marks are in the process and what happens next.
// Typed-but-unsaved marks are kept when moving between students, and leaving the page asks first.

const SCORE = /^\d{1,3}(\.\d{1,2})?$/;               // what the database column holds: up to 999.99
const norm = (s) => String(s ?? '').trim().replace(',', '.');
const canon = (s) => { const v = norm(s); return v !== '' && SCORE.test(v) ? String(Number(v)) : v; };
const fmt = (v) => (v === null || v === undefined || v === '' ? '' : String(Number(v)));

export default function Enter({ batch, exam, section, subject, roster, missing_mark_policy, readOnly = false, backTo }) {
    const { t } = useTranslation();
    const { errors } = usePage().props;
    const [ask, confirmDialog] = useConfirm();
    const isDraft = batch.status === 'draft' && !readOnly;

    const saved = useMemo(() => Object.fromEntries(roster.map((r) => [r.enrollment_id, fmt(r.score)])), [roster]);
    const [scores, setScores] = useState(saved);
    const [maxInput, setMaxInput] = useState(fmt(batch.max_score));
    const firstBlank = roster.findIndex((r) => saved[r.enrollment_id] === '');
    const [index, setIndex] = useState(firstBlank === -1 ? 0 : firstBlank);      // resume where the teacher stopped
    const [view, setView] = useState(firstBlank === -1 && roster.length > 0 ? 'list' : 'focus');   // focus | list | done
    const [saving, setSaving] = useState(false);
    const [notice, setNotice] = useState(null);
    const [justSubmitted, setJustSubmitted] = useState(false);
    const inputRef = useRef(null);

    const total = roster.length;
    const current = roster[index];
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
    const blanks = roster.filter((r) => valueOf(r) === '');

    useUnsavedGuard(dirty, () => ask({
        title: t('marks.leave_title'),
        body: <p>{t('marks.leave_body')}</p>,
        confirmLabel: t('marks.leave_leave'),
        cancelLabel: t('marks.leave_stay'),
        tone: 'danger',
    }));

    useEffect(() => {
        if (view === 'focus' && isDraft) {
            inputRef.current?.focus();
            inputRef.current?.select();
        }
    }, [index, view, isDraft]);

    useEffect(() => {
        if (!notice) return undefined;
        const timer = setTimeout(() => setNotice(null), 8000);
        return () => clearTimeout(timer);
    }, [notice]);

    const title = t('marks.enter_title', { subject: subject.name });
    const shownMax = fmt(max);

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

    const saveAndNext = (event) => {
        event.preventDefault();
        if (!current || saving || blocked) return;
        const last = index >= total - 1;
        const v = valueOf(current);
        save(() => {
            setNotice(v === '' ? t('marks.saved_note_blank', { name: current.student_name }) : t('marks.saved_note', { name: current.student_name, score: v, max: shownMax }));
            if (last) setView('done');
            else setIndex(index + 1);
        });
    };

    const saveAll = () => save(() => setNotice(t('marks.saved_note_all')));

    const goTo = (i) => { setNotice(null); setIndex(i); setView('focus'); };

    const submit = async () => {
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
    const currentBad = current ? problemOf(current) : null;
    const otherBad = invalid.find((r) => r !== current);
    const why = !maxOk ? t('marks.err_max_field') : currentBad ? null : otherBad ? t('marks.why_other_invalid', { name: otherBad.student_name }) : null;
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

    // ------------------------------------------------------------------ finished
    if (view === 'done') {
        return (
            <AuthenticatedLayout header={layoutHeader}>
                <Page title={title} width="narrow" back={back}>
                    <SuccessPanel
                        title={t('marks.done_title')}
                        details={
                            <div className="space-y-4">
                                {blanks.length > 0 && (
                                    <Alert tone="warning" title={t('marks.done_blank_title', { count: blanks.length })}>
                                        <p>{t('marks.done_blank_body')}</p>
                                        <ul className="mt-2 space-y-1">
                                            {blanks.slice(0, 8).map((r) => (
                                                <li key={r.enrollment_id}>
                                                    <button type="button" className="text-left font-semibold text-brand-800 underline dark:text-brand-300" onClick={() => goTo(roster.indexOf(r))}>{t('marks.done_go', { name: r.student_name })}</button>
                                                </li>
                                            ))}
                                            {blanks.length > 8 && <li>{t('marks.done_more', { n: blanks.length - 8 })}</li>}
                                        </ul>
                                    </Alert>
                                )}
                            </div>
                        }
                        actions={
                            <div className="flex w-full flex-col gap-3">
                                <Button fullWidth onClick={submit} disabled={blocked || entered === 0}>{t('marks.btn_submit')}</Button>
                                <Button fullWidth variant="secondary" onClick={() => setView('list')}>{t('marks.btn_keep_checking')}</Button>
                                <Button fullWidth as={Link} href={route('marks.index')} variant="quiet">{t('marks.back_to_marks')}</Button>
                            </div>
                        }
                    >
                        {t('marks.done_recorded', { entered, count: total })}
                    </SuccessPanel>
                </Page>
                {confirmDialog}
            </AuthenticatedLayout>
        );
    }

    // ------------------------------------------------------------------ marking
    const problem = current ? problemOf(current) : null;
    const currentValue = current ? valueOf(current) : '';
    const savedValue = current ? saved[current.enrollment_id] : '';
    const isLast = index >= total - 1;
    const next = roster[index + 1];

    return (
        <AuthenticatedLayout header={layoutHeader}>
            <Page title={title} width="narrow" back={back}>
                {serverError && <Alert tone="danger" title={t('marks.error_title')}>{serverError}</Alert>}
                <Card>{facts}</Card>

                {total === 0 ? (
                    <Card><EmptyState icon={UsersIcon} title={t('marks.empty_roster')} /></Card>
                ) : (
                    <>
                        <ChoiceGroup
                            layout="segmented"
                            label={t('marks.view_label')}
                            value={view}
                            onChange={(v) => { setView(v); setNotice(null); }}
                            options={[{ value: 'focus', label: t('marks.view_focus') }, { value: 'list', label: t('marks.view_list') }]}
                        />

                        <div aria-live="polite">
                            {notice && (
                                <Alert tone="success" onDismiss={() => setNotice(null)}><span data-testid="saved-notice">{notice}</span></Alert>
                            )}
                        </div>

                        {view === 'focus' && current && (
                            <Card className="border-2 border-brand-500 dark:border-brand-400" data-testid="current-student">
                                <div className="flex flex-wrap items-baseline justify-between gap-2">
                                    <p className="text-lg font-bold text-brand-800 dark:text-brand-300" data-testid="position">{t('marks.student_of', { n: index + 1, total })}</p>
                                    <p className="text-base text-stone-700 dark:text-stone-300" data-testid="entered-progress">{t('marks.entered_progress', { entered, count: total })}</p>
                                </div>
                                <div className="mt-2 h-2 w-full overflow-hidden rounded-full bg-stone-200 dark:bg-stone-700" role="progressbar" aria-valuemin={1} aria-valuemax={total} aria-valuenow={index + 1} aria-label={t('marks.student_of', { n: index + 1, total })}>
                                    <div className="h-2 rounded-full bg-brand-600" style={{ width: `${((index + 1) / total) * 100}%` }} />
                                </div>

                                <h3 className="mt-5 break-words text-3xl font-bold text-stone-900 dark:text-stone-100" data-testid="student-name">{current.student_name}</h3>
                                <p className="text-base text-stone-600 dark:text-stone-400">{current.student_id_number}</p>

                                <p className="mt-3 flex flex-wrap items-center gap-2 text-base font-semibold" data-testid="saved-line">
                                    {savedValue === '' ? (
                                        <span className="text-stone-700 dark:text-stone-300">{t('marks.no_saved_mark')}</span>
                                    ) : currentValue !== savedValue ? (
                                        <span className="text-amber-900 dark:text-amber-300">{t('marks.changed_from', { score: savedValue })}</span>
                                    ) : (
                                        <span className="inline-flex items-center gap-1.5 text-emerald-800 dark:text-emerald-300"><CheckCircleIcon className="h-5 w-5" aria-hidden="true" />{t('marks.saved_mark', { score: savedValue, max: shownMax })}</span>
                                    )}
                                </p>

                                <form onSubmit={saveAndNext} className="mt-5">
                                    <label htmlFor="mark-input" className="block text-lg font-semibold text-stone-900 dark:text-stone-100">{t('marks.input_label', { max: shownMax })}</label>
                                    <div className="mt-2 flex items-center gap-3">
                                        <input
                                            id="mark-input"
                                            ref={inputRef}
                                            type="text"
                                            inputMode="decimal"
                                            autoComplete="off"
                                            value={scores[current.enrollment_id] ?? ''}
                                            onChange={(e) => setScores((s) => ({ ...s, [current.enrollment_id]: e.target.value }))}
                                            aria-invalid={problem ? true : undefined}
                                            aria-describedby={problem ? 'mark-error' : 'mark-hint'}
                                            className={`h-20 w-full min-w-0 rounded-2xl border-2 bg-white text-center text-4xl font-bold text-stone-900 shadow-sm focus:ring-4 dark:bg-stone-900 dark:text-stone-100 ${problem ? 'border-red-600 focus:border-red-600 focus:ring-red-200' : 'border-stone-400 focus:border-brand-600 focus:ring-brand-200 dark:border-stone-500'}`}
                                        />
                                        <span className="whitespace-nowrap text-2xl font-semibold text-stone-600 dark:text-stone-300">/ {shownMax}</span>
                                    </div>
                                    {problem ? (
                                        <p id="mark-error" role="alert" className="mt-2 flex items-center gap-1.5 text-base font-semibold text-red-700 dark:text-red-300"><ExclamationTriangleIcon className="h-5 w-5" aria-hidden="true" />{problemText(current)}</p>
                                    ) : (
                                        <p id="mark-hint" className="mt-2 text-base text-stone-600 dark:text-stone-400">{t('marks.input_hint')}</p>
                                    )}

                                    <div className="sticky bottom-0 z-10 -mx-5 mt-5 border-t-2 border-stone-200 bg-white/95 px-5 py-3 backdrop-blur dark:border-stone-700 dark:bg-stone-800/95 sm:static sm:mx-0 sm:border-0 sm:bg-transparent sm:p-0 sm:backdrop-blur-none">
                                        <Button key={isLast ? 'finish' : 'next'} type="submit" fullWidth busy={saving} disabled={blocked} className="!min-h-[64px] text-xl">
                                            {isLast ? t('marks.btn_save_finish') : t('marks.btn_save_next')}
                                        </Button>
                                    </div>
                                    {blocked && why && <p className="mt-2 text-sm font-medium text-stone-700 dark:text-stone-300" role="status">{why}</p>}
                                    {!isLast && next && !blocked && <p className="mt-2 text-center text-sm text-stone-600 dark:text-stone-400" data-testid="next-up">{t('marks.next_up', { name: next.student_name })}</p>}
                                </form>

                                <div className="mt-5 grid grid-cols-2 gap-3">
                                    <Button variant="secondary" icon={ArrowLeftIcon} disabled={index === 0 || blocked} onClick={() => goTo(index - 1)}>{t('marks.btn_prev')}</Button>
                                    <Button variant="secondary" icon={ArrowRightIcon} disabled={isLast || blocked} onClick={() => goTo(index + 1)}>{t('marks.btn_next')}</Button>
                                </div>
                                {dirty && <p className="mt-3 text-sm font-medium text-amber-900 dark:text-amber-300" data-testid="unsaved-hint">{t('marks.unsaved_hint', { count: changed.length })}</p>}
                            </Card>
                        )}

                        {view === 'list' && (
                            <Card>
                                <p className="text-lg font-bold text-stone-900 dark:text-stone-100" data-testid="entered-progress">{t('marks.entered_progress', { entered, count: total })}</p>
                                <ul className="mt-4 divide-y divide-stone-200 dark:divide-stone-700" data-testid="student-list">
                                    {roster.map((r, i) => {
                                        const v = valueOf(r);
                                        const p = problemOf(r);
                                        const state = p ? 'invalid' : isChanged(r) ? 'unsaved' : v === '' ? 'blank' : 'saved';
                                        return (
                                            <li key={r.enrollment_id}>
                                                <button type="button" data-state={state} aria-label={t('marks.list_open', { name: r.student_name })} onClick={() => goTo(i)} className="flex min-h-[64px] w-full items-center justify-between gap-3 py-3 text-left focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500">
                                                    <span className="min-w-0">
                                                        <span className="block text-lg font-semibold text-stone-900 dark:text-stone-100">{r.student_name}</span>
                                                        <span className="block text-sm text-stone-600 dark:text-stone-400">{r.student_id_number}</span>
                                                    </span>
                                                    <span className="text-right">
                                                        <span className="block text-xl font-bold text-stone-900 dark:text-stone-100">{v === '' ? <span className="text-base font-medium text-stone-600 dark:text-stone-400">{t('marks.list_no_mark')}</span> : `${v} / ${shownMax}`}</span>
                                                        {state === 'unsaved' && <span className="block text-sm font-semibold text-amber-900 dark:text-amber-300">{t('marks.list_unsaved')}</span>}
                                                        {state === 'invalid' && <span className="block text-sm font-semibold text-red-700 dark:text-red-300">{problemText(r)}</span>}
                                                        {state === 'saved' && <span className="inline-flex items-center gap-1 text-sm font-semibold text-emerald-800 dark:text-emerald-300"><CheckCircleIcon className="h-4 w-4" aria-hidden="true" />{t('marks.list_saved')}</span>}
                                                    </span>
                                                </button>
                                            </li>
                                        );
                                    })}
                                </ul>
                                <div className="mt-5 space-y-3">
                                    <Button fullWidth variant={dirty ? 'primary' : 'secondary'} busy={saving} disabled={blocked || !dirty} onClick={saveAll}>{t('marks.btn_save_only')}</Button>
                                    {blocked && why && <p className="text-sm font-medium text-stone-700 dark:text-stone-300" role="status">{why}</p>}
                                </div>
                            </Card>
                        )}

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
