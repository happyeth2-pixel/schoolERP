import SectionPicker from '@/Components/SectionPicker';
import { Alert, BackLink, Button, Card, Disclosure, EmptyState, Field, FlashMessages, Input, Page, ReviewList, SuccessPanel, WizardShell, useWizard } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';

// Moving a class up, as four short questions: WHICH class, WHO (each student: moves up / graduates / not this
// time -- nobody is decided for you), WHERE (the next class, with the seat count and the capacity rules) and a
// CHECK that reads it all back before the one button that changes anything. It sends exactly what the old page
// sent to the same endpoint, which keeps everything the server guarantees: the branch scope, seats in the
// destination (unless someone allowed may override with a reason), one placement per student even if sent twice,
// and the audit entries. Nothing is calculated here that the server does not check again.

const MANY = 8;

function ClassGrid({ sections, value, onPick, branchCount }) {
    const { t } = useTranslation();
    const years = useMemo(() => {
        const m = new Map();
        sections.forEach((s) => m.set(s.academic_year_id, { id: s.academic_year_id, name: s.academic_year, current: s.is_current_year }));
        return [...m.values()];
    }, [sections]);
    const selected = sections.find((s) => s.id === value);
    const [year, setYear] = useState(selected?.academic_year_id ?? years.find((y) => y.current)?.id ?? years[0]?.id ?? null);
    const inYear = sections.filter((s) => s.academic_year_id === year);
    const grades = [...new Map(inYear.map((s) => [s.grade, s.grade_order ?? 0])).entries()].sort((a, b) => a[1] - b[1]).map(([g]) => g);
    const [grade, setGrade] = useState(selected?.grade ?? '');
    const grouped = inYear.length > MANY;
    const visible = grouped ? inYear.filter((s) => s.grade === grade) : inYear;

    const pill = (on) => `min-h-[56px] rounded-xl border-2 px-4 text-base font-semibold focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 ${on ? 'border-brand-600 bg-brand-600 text-white' : 'border-stone-300 bg-white text-stone-900 hover:border-brand-400 dark:border-stone-600 dark:bg-stone-800 dark:text-stone-100'}`;

    return (
        <div className="space-y-5">
            {years.length > 1 && (
                <div>
                    <p className="mb-2 text-lg font-semibold text-stone-900 dark:text-stone-100">{t('promo.which_year')}</p>
                    <div role="radiogroup" aria-label={t('promo.which_year')} className="flex flex-wrap gap-2">
                        {years.map((y) => <button key={y.id} type="button" role="radio" aria-checked={year === y.id} onClick={() => { setYear(y.id); setGrade(''); }} className={pill(year === y.id)}>{y.name}{y.current ? ` · ${t('promo.current')}` : ''}</button>)}
                    </div>
                </div>
            )}
            {grouped && (
                <div>
                    <p className="mb-2 text-lg font-semibold text-stone-900 dark:text-stone-100">{t('promo.which_grade')}</p>
                    <div role="radiogroup" aria-label={t('promo.which_grade')} className="flex flex-wrap gap-2">
                        {grades.map((g) => <button key={g} type="button" role="radio" aria-checked={grade === g} onClick={() => setGrade(g)} className={`${pill(grade === g)} min-w-[72px]`}>{g}</button>)}
                    </div>
                </div>
            )}
            <div>
                <p className="mb-2 text-lg font-semibold text-stone-900 dark:text-stone-100">{t('promo.which_class')}</p>
                {grouped && !grade ? (
                    <p className="text-base text-stone-600 dark:text-stone-400">{t('promo.pick_grade_first')}</p>
                ) : (
                    <div role="radiogroup" aria-label={t('promo.which_class')} className="grid gap-3 sm:grid-cols-2" data-testid="from-classes">
                        {visible.map((s) => {
                            const on = s.id === value;
                            return (
                                <button key={s.id} type="button" role="radio" aria-checked={on} onClick={() => onPick(s.id)} className={`flex min-h-[72px] flex-col items-start justify-center rounded-xl border-2 px-4 py-2 text-left transition focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 ${on ? 'border-brand-600 bg-brand-50 dark:bg-brand-900/30' : 'border-stone-300 bg-white hover:border-brand-400 dark:border-stone-600 dark:bg-stone-800'}`}>
                                    <span className="text-lg font-bold text-stone-900 dark:text-stone-100">{s.class_name}</span>
                                    <span className="text-base text-stone-700 dark:text-stone-300">{branchCount > 1 ? `${s.branch} · ` : ''}{t('promo.students_n', { count: s.occupied })}</span>
                                </button>
                            );
                        })}
                    </div>
                )}
            </div>
        </div>
    );
}

const CHOICES = ['move', 'graduate', 'skip'];

export default function Index({ sections, fromSectionId, students, canOverrideCapacity, branchCount, result }) {
    const { t } = useTranslation();
    const { auth } = usePage().props;
    const back = <BackLink big={auth.role === 'teacher'} href={route('dashboard')}>{t('common.back')}</BackLink>;
    const from = sections.find((s) => s.id === fromSectionId);
    const [decisions, setDecisions] = useState({});
    const [dismissed, setDismissed] = useState(false);
    const [loading, setLoading] = useState(false);
    useEffect(() => { if (result) setDismissed(false); }, [result]);
    useEffect(() => { setDecisions({}); }, [fromSectionId]);

    const form = useForm({ to_section_id: '', override_reason: '' });
    const { data, setData, errors, processing, clearErrors } = form;

    const pickFrom = (id) => {
        if (id === fromSectionId) return;
        setLoading(true);
        router.get(route('promotions.index'), { from: id }, { preserveState: true, preserveScroll: true, only: ['fromSectionId', 'students'], onFinish: () => setLoading(false) });
    };

    const decide = (id, choice) => setDecisions((d) => ({ ...d, [id]: choice }));
    const decideAll = (choice) => setDecisions(Object.fromEntries(students.map((s) => [s.enrollment_id, choice])));
    const of = (c) => students.filter((s) => decisions[s.enrollment_id] === c);
    const movers = of('move');
    const graduates = of('graduate');
    const skipped = of('skip');
    const undecided = students.length - movers.length - graduates.length - skipped.length;

    const to = sections.find((s) => String(s.id) === String(data.to_section_id));
    const short = to && to.capacity != null && !to.full && to.seats_left < movers.length;
    // Someone holding override-section-capacity places everyone automatically (SectionCapacity::grant()) --
    // no checkbox required. This preview must agree with that, or it would wrongly tell them some students
    // "stay behind" when the server is actually about to place all of them anyway.
    const placeable = to ? (to.capacity == null || canOverrideCapacity ? movers.length : Math.min(movers.length, to.seats_left)) : 0;
    const stayBehind = movers.length - placeable;

    // a hint only: the grade after the one moving out is opened first (the class itself is still chosen by the person)
    const nextGrade = from ? [...new Map(sections.filter((x) => x.grade_order > from.grade_order).map((x) => [x.grade, x.grade_order])).entries()].sort((a, b) => a[1] - b[1])[0]?.[0] : undefined;

    const finalLabel = movers.length && graduates.length ? t('promo.final_both', { moves: movers.length, grads: graduates.length }) : movers.length ? t('promo.final_move', { count: movers.length }) : t('promo.final_grad', { count: graduates.length });

    const steps = [
        { key: 'class', label: t('promo.step_class'), description: t('promo.step_class_desc'), isValid: () => Boolean(from) && students.length > 0 && !loading, whyBlocked: from && students.length === 0 ? t('promo.why_no_students') : t('promo.why_class') },
        { key: 'who', label: t('promo.step_who'), description: t('promo.step_who_desc'), fields: ['promotions'], isValid: () => undecided === 0 && movers.length + graduates.length > 0, whyBlocked: undecided > 0 ? t('promo.why_undecided', { count: undecided }) : t('promo.why_nobody') },
        { key: 'where', label: t('promo.step_where'), description: t('promo.step_where_desc'), fields: ['to_section_id'], isValid: () => movers.length === 0 || (Boolean(to) && (!(to.capacity != null && to.full) || canOverrideCapacity)), whyBlocked: t('promo.why_where') },
        { key: 'review', label: t('promo.step_review'), description: t('promo.step_review_desc') },
    ];

    const wizard = useWizard({
        steps,
        errors,
        onSubmit: () => {
            const promotions = students.filter((s) => decisions[s.enrollment_id] !== 'skip').map((s) => ({ enrollment_id: s.enrollment_id, graduate: decisions[s.enrollment_id] === 'graduate' }));
            form.transform((d) => ({ from_section_id: fromSectionId, to_section_id: movers.length ? d.to_section_id : null, override_reason: d.override_reason, promotions }));
            form.post(route('promotions.store'), { preserveScroll: true });
        },
    });
    const key = wizard.step.key;
    const stepOf = (k) => steps.findIndex((s) => s.key === k);

    const header = <h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">{t('promo.page_title')}</h2>;

    // ------------------------------------------------------------------ done
    if (result && !dismissed) {
        const c = result.counts;
        const lines = [
            [c.promoted, 'promo.res_promoted'], [c.graduated, 'promo.res_graduated'], [c.already_processed + c.already_in_destination, 'promo.res_already'],
            [c.not_eligible, 'promo.res_not_eligible'], [c.no_room, 'promo.res_no_room'], [c.not_found, 'promo.res_not_found'],
        ].filter(([n]) => n > 0);
        const done = c.promoted + c.graduated;

        return (
            <AuthenticatedLayout header={header}>
                <Page title={t('promo.page_title')} width="default" back={back}>
                    <SuccessPanel
                        title={done === 0 ? t('promo.res_nothing') : t('promo.res_title')}
                        details={
                            <ul className="space-y-1 text-base text-emerald-950 dark:text-emerald-50" data-testid="result-lines">
                                {lines.map(([n, k]) => <li key={k}>{t(k, { count: n })}</li>)}
                                {result.to && <li className="pt-2 font-medium">{t('promo.res_from_to', { from: result.from, to: result.to })}</li>}
                            </ul>
                        }
                        actions={
                            <div className="flex w-full flex-col gap-3">
                                <Button fullWidth onClick={() => { setDismissed(true); setDecisions({}); form.setData({ to_section_id: '', override_reason: '' }); wizard.reset(); }}>{t('promo.another')}</Button>
                                <Button as={Link} href={route('students.index')} variant="quiet" fullWidth>{t('promo.see_students')}</Button>
                            </div>
                        }
                    >
                        {result.summary}
                    </SuccessPanel>
                </Page>
            </AuthenticatedLayout>
        );
    }

    const movesText = t('promo.n_move', { count: movers.length });

    return (
        <AuthenticatedLayout header={header}>
            <Page title={t('promo.page_title')} description={t('promo.page_desc')} width="default" back={back}>
                <FlashMessages skip={['success']} />
                {sections.length === 0 ? (
                    <EmptyState title={t('promo.no_classes_title')}>{t('promo.no_classes_body')}</EmptyState>
                ) : (
                    <Card>
                        <WizardShell wizard={wizard} finalLabel={finalLabel} processing={processing}>
                            {key === 'class' && (
                                <div className="space-y-5">
                                    <ClassGrid sections={sections} value={fromSectionId} onPick={pickFrom} branchCount={branchCount} />
                                    {from && !loading && (
                                        <p className="rounded-xl bg-stone-50 p-4 text-base text-stone-800 dark:bg-stone-900/40 dark:text-stone-200" data-testid="class-count">
                                            {students.length === 0 ? t('promo.none_enrolled', { class: from.class_name }) : t('promo.enrolled_n', { count: students.length, class: from.class_name })}
                                        </p>
                                    )}
                                </div>
                            )}

                            {key === 'who' && (
                                <div className="space-y-4">
                                    <div className="flex flex-wrap gap-3">
                                        <Button variant="secondary" onClick={() => decideAll('move')}>{t('promo.all_move')}</Button>
                                        <Button variant="secondary" onClick={() => decideAll('graduate')}>{t('promo.all_graduate')}</Button>
                                    </div>
                                    <p className="text-base font-semibold text-stone-900 dark:text-stone-100" data-testid="decided-count">{t('promo.decided', { done: students.length - undecided, total: students.length })}</p>
                                    <ul className="divide-y divide-stone-200 rounded-xl border-2 border-stone-200 dark:divide-stone-700 dark:border-stone-700" data-testid="students">
                                        {students.map((s) => {
                                            const d = decisions[s.enrollment_id];
                                            return (
                                                <li key={s.enrollment_id} data-testid="student-row" className="flex flex-col gap-2 p-3 sm:flex-row sm:items-center sm:justify-between">
                                                    <span className="min-w-0"><span className="block text-lg font-medium text-stone-900 dark:text-stone-100">{s.name}</span><span className="block text-sm text-stone-600 dark:text-stone-400">{s.student_id_number}</span></span>
                                                    <span role="radiogroup" aria-label={s.name} className="flex gap-2">
                                                        {CHOICES.map((c) => (
                                                            <button key={c} type="button" role="radio" aria-checked={d === c} onClick={() => decide(s.enrollment_id, c)}
                                                                className={`min-h-[48px] flex-1 rounded-xl border-2 px-3 text-base font-semibold focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 sm:flex-none ${d === c ? (c === 'skip' ? 'border-stone-700 bg-stone-700 text-white' : c === 'graduate' ? 'border-emerald-700 bg-emerald-700 text-white' : 'border-brand-600 bg-brand-600 text-white') : 'border-stone-300 bg-white text-stone-900 hover:border-brand-400 dark:border-stone-600 dark:bg-stone-800 dark:text-stone-100'}`}>
                                                                {t(`promo.c_${c}`)}
                                                            </button>
                                                        ))}
                                                    </span>
                                                </li>
                                            );
                                        })}
                                    </ul>
                                </div>
                            )}

                            {key === 'where' && (
                                <div className="space-y-5">
                                    {movers.length === 0 ? (
                                        <p className="rounded-xl bg-stone-50 p-4 text-base text-stone-800 dark:bg-stone-900/40 dark:text-stone-200" data-testid="no-destination">{t('promo.no_destination')}</p>
                                    ) : (
                                        <>
                                            <p className="text-base text-stone-700 dark:text-stone-300">{t('promo.where_intro', { moves: movesText, class: from?.class_name })}</p>
                                            <SectionPicker
                                                sections={sections.filter((s) => s.id !== fromSectionId)}
                                                value={data.to_section_id}
                                                onChange={(id) => { clearErrors('to_section_id'); setData((d) => ({ ...d, to_section_id: id, override_reason: '' })); }}
                                                canOverride={canOverrideCapacity}
                                                overrideReason={data.override_reason}
                                                onOverrideReason={(v) => setData('override_reason', v)}
                                                personName={t('promo.these_students')}
                                                errors={{ section_id: errors.to_section_id, override_reason: errors.override_reason }}
                                                label={t('promo.where_label')}
                                                initialGrade={nextGrade}
                                            />
                                            {short && (
                                                <div data-testid="short-warning"><Alert tone="warning" title={t('promo.short_title', { class: to.class_name, left: to.seats_left, count: movers.length })}>
                                                    {canOverrideCapacity ? (
                                                        <div className="space-y-3">
                                                            <p>{t('promo.short_body_override')}</p>
                                                            <Field id="promo_override_reason" label={t('promo.override_reason')} hint={t('promo.override_hint')} optional error={errors.override_reason}>
                                                                <Input value={data.override_reason} onChange={(e) => setData('override_reason', e.target.value)} />
                                                            </Field>
                                                        </div>
                                                    ) : (
                                                        <>
                                                            <p className="mb-3">{t('promo.short_body', { stay: movers.length - to.seats_left })}</p>
                                                            <p>{t('promo.ask_admin')}</p>
                                                        </>
                                                    )}
                                                </Alert></div>
                                            )}
                                        </>
                                    )}
                                </div>
                            )}

                            {key === 'review' && (
                                <div className="space-y-5">
                                    <div className="rounded-2xl border-2 border-brand-300 bg-brand-50 p-5 dark:border-brand-700 dark:bg-brand-900/20" data-testid="headline">
                                        {movers.length > 0 && <p className="text-2xl font-bold text-stone-900 dark:text-stone-100">{t('promo.head_move', { count: movers.length, to: to?.class_name })}</p>}
                                        {graduates.length > 0 && <p className="text-2xl font-bold text-stone-900 dark:text-stone-100">{t('promo.head_grad', { count: graduates.length })}</p>}
                                        {skipped.length > 0 && <p className="mt-1 text-lg text-stone-800 dark:text-stone-200">{t('promo.head_skip', { count: skipped.length })}</p>}
                                    </div>
                                    <ReviewList
                                        onEdit={wizard.goTo}
                                        rows={[
                                            { label: t('promo.r_from'), value: from?.label ?? '', step: stepOf('class') },
                                            { label: t('promo.r_who'), value: t('promo.r_who_value', { moves: movers.length, grads: graduates.length, skip: skipped.length }), step: stepOf('who') },
                                            ...(movers.length ? [{ label: t('promo.r_to'), value: to?.label ?? '', step: stepOf('where') }] : []),
                                        ]}
                                    />
                                    {[['move', movers], ['graduate', graduates], ['skip', skipped]].filter(([, l]) => l.length > 0).map(([c, l]) => (
                                        <Disclosure key={c} title={`${t(`promo.g_${c}`)} (${l.length})`} data-testid={`group-${c}`}>
                                            <ul className="columns-1 gap-6 sm:columns-2">{l.map((s) => <li key={s.enrollment_id} className="text-base text-stone-900 dark:text-stone-100">{s.name}</li>)}</ul>
                                        </Disclosure>
                                    ))}
                                    {stayBehind > 0 && <Alert tone="warning" title={t('promo.stay_title', { count: stayBehind })}>{t('promo.stay_body')}</Alert>}
                                    {canOverrideCapacity && to?.capacity != null && (to.full || short) && <Alert tone="info">{t('promo.override_note', { reason: data.override_reason })}</Alert>}
                                    <p className="text-base text-stone-700 dark:text-stone-300">{t('promo.review_note')}</p>
                                </div>
                            )}
                        </WizardShell>
                    </Card>
                )}
            </Page>
        </AuthenticatedLayout>
    );
}
