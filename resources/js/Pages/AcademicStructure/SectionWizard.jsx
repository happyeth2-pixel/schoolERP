import { Alert, BackLink, Button, Card, ChoiceGroup, Field, Input, Page, ReviewList, SuccessPanel, WizardShell, useWizard } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Link, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';

// Adding a section (a class group such as "Grade 5 B") as three short questions: WHERE it belongs (grade, branch,
// school year), WHAT it is called and how many students fit, then a check. It sends the same fields to the same
// endpoint as before, which keeps every rule: branch and school scope, the capacity limit, and one section of a
// given name per grade, branch and year (now explained in words instead of failing).

const LETTERS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'.split('');

export default function SectionWizard({ grades, branches, academicYears, existing, presetGrade, saved }) {
    const { t } = useTranslation();
    const currentYear = academicYears.find((y) => y.is_current);

    const form = useForm({
        grade_id: presetGrade && grades.some((g) => g.id === presetGrade) ? String(presetGrade) : '',
        branch_id: branches.length === 1 ? String(branches[0].id) : '',
        academic_year_id: currentYear ? String(currentYear.id) : '',
        name: '',
        capacity: '',
    });
    const { data, setData, errors, processing, clearErrors } = form;
    const set = (k) => (v) => { clearErrors(k); setData(k, v); };

    const [dismissed, setDismissed] = useState(false);
    useEffect(() => { if (saved) setDismissed(false); }, [saved]);

    const grade = grades.find((g) => String(g.id) === data.grade_id);
    const branch = branches.find((b) => String(b.id) === data.branch_id);
    const year = academicYears.find((y) => String(y.id) === data.academic_year_id);
    const here = existing.filter((s) => String(s.grade_id) === data.grade_id && String(s.branch_id) === data.branch_id && String(s.academic_year_id) === data.academic_year_id);
    const taken = new Set(here.map((s) => s.name.trim().toLowerCase()));
    const suggestions = LETTERS.filter((l) => !taken.has(l.toLowerCase())).slice(0, 4);

    const nameTrim = data.name.trim();
    const duplicate = taken.has(nameTrim.toLowerCase()) && nameTrim !== '';
    const capTrim = String(data.capacity).trim();
    const capOk = capTrim === '' || (/^\d{1,4}$/.test(capTrim) && Number(capTrim) >= 1);

    const steps = [
        {
            key: 'where', label: t('sect.step_where'), description: t('sect.step_where_desc'), fields: ['grade_id', 'branch_id', 'academic_year_id'],
            isValid: () => Boolean(data.grade_id && data.branch_id && data.academic_year_id), whyBlocked: t('sect.why_where'),
        },
        {
            key: 'details', label: t('sect.step_details'), description: t('sect.step_details_desc'), fields: ['name', 'capacity'],
            isValid: () => nameTrim !== '' && !duplicate && capOk, whyBlocked: t('sect.why_details'),
        },
        { key: 'review', label: t('sect.step_review'), description: t('sect.step_review_desc') },
    ];

    const wizard = useWizard({
        steps,
        errors,
        onSubmit: () => {
            form.transform((d) => ({ ...d, name: nameTrim, capacity: capTrim === '' ? null : Number(capTrim) }));
            form.post(route('sections.store'), { preserveScroll: true });
        },
    });
    const key = wizard.step.key;
    const stepOf = (k) => steps.findIndex((s) => s.key === k);

    const title = t('sect.title_add');
    const layoutHeader = <h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">{title}</h2>;
    const back = <BackLink href={route('academic-structure.index')}>{t('sect.back')}</BackLink>;

    if (saved && !dismissed) {
        return (
            <AuthenticatedLayout header={layoutHeader}>
                <Page title={title} width="default">
                    <SuccessPanel
                        title={t('sect.saved_title', { grade: saved.grade, name: saved.name })}
                        details={<p className="text-base text-emerald-950 dark:text-emerald-50">{t('sect.saved_note')}</p>}
                        actions={
                            <div className="flex w-full flex-col gap-3">
                                <Button fullWidth onClick={() => { setDismissed(true); setData((d) => ({ ...d, name: '', capacity: '' })); wizard.goTo(stepOf('details')); }}>{t('sect.add_another', { grade: saved.grade })}</Button>
                                <Button variant="secondary" fullWidth onClick={() => { setDismissed(true); setData((d) => ({ ...d, grade_id: '', name: '', capacity: '' })); wizard.reset(); }}>{t('sect.add_other_grade')}</Button>
                                <Button as={Link} href={route('teaching-assignments.index')} variant="secondary" fullWidth>{t('sect.choose_teachers')}</Button>
                                <Button as={Link} href={route('academic-structure.index')} variant="quiet" fullWidth>{t('sect.see_all')}</Button>
                            </div>
                        }
                    >
                        {t('sect.saved_line', { grade: saved.grade, name: saved.name, branch: saved.branch, year: saved.academic_year })}
                    </SuccessPanel>
                </Page>
            </AuthenticatedLayout>
        );
    }

    const rows = [
        { label: t('sect.r_grade'), value: grade?.name ?? '', step: stepOf('where') },
        ...(branches.length > 1 ? [{ label: t('sect.r_branch'), value: branch?.name ?? '', step: stepOf('where') }] : []),
        { label: t('sect.r_year'), value: year?.name ?? '', step: stepOf('where') },
        { label: t('sect.r_name'), value: nameTrim, step: stepOf('details') },
        { label: t('sect.r_capacity'), value: capTrim === '' ? t('sect.no_limit') : t('sect.students_n', { count: Number(capTrim) }), step: stepOf('details') },
    ];

    return (
        <AuthenticatedLayout header={layoutHeader}>
            <Page title={title} description={t('sect.desc_add')} width="default" back={back}>
                <Card>
                    <WizardShell wizard={wizard} finalLabel={t('sect.save_button')} processing={processing}>
                        {key === 'where' && (
                            <div className="space-y-6">
                                <div>
                                    <p className="mb-2 text-lg font-semibold text-gray-900 dark:text-gray-100">{t('sect.grade')}</p>
                                    <div role="radiogroup" aria-label={t('sect.grade')} className="grid grid-cols-2 gap-3 sm:grid-cols-3" data-testid="grade-grid">
                                        {grades.map((g) => {
                                            const selected = String(g.id) === data.grade_id;
                                            return (
                                                <button key={g.id} type="button" role="radio" aria-checked={selected} onClick={() => set('grade_id')(String(g.id))}
                                                    className={`flex min-h-[64px] items-center justify-center rounded-xl border-2 px-3 py-2 text-center text-lg font-bold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 ${selected ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-gray-300 bg-white text-gray-900 hover:border-indigo-400 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100'}`}>
                                                    {g.name}
                                                </button>
                                            );
                                        })}
                                    </div>
                                    {grades.length === 0 && <Alert tone="warning" title={t('sect.no_grades_title')} className="mt-3">{t('sect.no_grades_body')}</Alert>}
                                </div>
                                {branches.length > 1 && (
                                    <ChoiceGroup heading={t('sect.branch')} value={data.branch_id} onChange={set('branch_id')} options={branches.map((b) => ({ value: String(b.id), label: b.name }))} />
                                )}
                                <ChoiceGroup
                                    heading={t('sect.year')}
                                    value={data.academic_year_id}
                                    onChange={set('academic_year_id')}
                                    options={academicYears.map((y) => ({ value: String(y.id), label: y.name, hint: y.is_current ? t('sect.year_current') : undefined }))}
                                />
                                {grade && branch && year && (
                                    <div className="rounded-xl bg-gray-50 p-4 dark:bg-gray-900/40" data-testid="existing">
                                        <p className="text-lg font-semibold text-gray-900 dark:text-gray-100">{t('sect.existing_title', { grade: grade.name, year: year.name })}</p>
                                        <p className="mt-1 text-base text-gray-700 dark:text-gray-300">
                                            {here.length === 0 ? t('sect.existing_none') : here.map((s) => s.name).sort().join(', ')}
                                        </p>
                                    </div>
                                )}
                            </div>
                        )}

                        {key === 'details' && (
                            <div className="space-y-6">
                                <Field id="section_name" label={t('sect.name_label')} hint={t('sect.name_hint', { grade: grade?.name })} error={duplicate ? t('sect.err_dup', { name: nameTrim }) : errors.name}>
                                    <Input value={data.name} onChange={(e) => set('name')(e.target.value)} autoFocus autoComplete="off" maxLength={50} />
                                </Field>
                                {suggestions.length > 0 && (
                                    <div className="-mt-3">
                                        <p className="mb-1 text-sm text-gray-600 dark:text-gray-400">{t('sect.name_suggest')}</p>
                                        <div className="flex flex-wrap gap-2">
                                            {suggestions.map((s) => <button key={s} type="button" onClick={() => set('name')(s)} className="min-h-[44px] min-w-[56px] rounded-xl border-2 border-gray-300 bg-white px-3 text-base font-bold text-gray-900 hover:border-indigo-400 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">{s}</button>)}
                                        </div>
                                    </div>
                                )}
                                <Field id="section_capacity" label={t('sect.capacity_label')} hint={t('sect.capacity_hint')} optional error={!capOk ? t('sect.err_capacity') : errors.capacity}>
                                    <Input inputMode="numeric" value={data.capacity} onChange={(e) => set('capacity')(e.target.value)} autoComplete="off" />
                                </Field>
                            </div>
                        )}

                        {key === 'review' && (
                            <div className="space-y-4">
                                <div className="rounded-2xl border-2 border-indigo-300 bg-indigo-50 p-5 dark:border-indigo-700 dark:bg-indigo-900/20" data-testid="headline">
                                    <p className="text-2xl font-bold text-gray-900 dark:text-gray-100">{grade?.name} {nameTrim}</p>
                                    <p className="mt-1 text-lg text-gray-800 dark:text-gray-200">{[branches.length > 1 ? branch?.name : null, year?.name].filter(Boolean).join(' · ')}</p>
                                </div>
                                <ReviewList rows={rows} onEdit={wizard.goTo} />
                                <p className="text-base text-gray-700 dark:text-gray-300">{t('sect.review_note')}</p>
                            </div>
                        )}
                    </WizardShell>
                </Card>
            </Page>
        </AuthenticatedLayout>
    );
}
