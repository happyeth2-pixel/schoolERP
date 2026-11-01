import { Alert, BackLink, Button, Card, ChoiceGroup, Field, Input, Page, ReviewList, Select, SuccessPanel, WizardShell, useWizard } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Link, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';

const WEEKDAYS = [1, 2, 3, 4, 5, 6, 7]; // ISO-8601: Monday..Sunday, same convention TimetableSlot uses

// Adding an extra service (a transport route, an after-school class), as four short questions: WHERE it applies
// (branch, school year), WHAT it is (name, kind), what it COSTS (amount, how often), then a check. Same fields and
// same endpoint as before, so the rules stay: branch and school scope, and a transport service is one route per
// student (enforced when a student joins). Nothing is charged until a student is enrolled in the service.

const AMOUNT = /^\d{1,8}(\.\d{1,2})?$/;
const norm = (s) => String(s ?? '').trim().replace(',', '.');
const money = (n) => Number(n).toLocaleString(undefined, { maximumFractionDigits: 2 });
const KINDS = ['transport', 'asp', 'other'];
const FREQS = ['annual', 'termly', 'monthly'];

export default function Wizard({ branches, academicYears, existing, periods, teachers, saved }) {
    const { t } = useTranslation();
    const form = useForm({
        branch_id: branches.length === 1 ? String(branches[0].id) : '', academic_year_id: '', name: '', category: '', amount: '', frequency: '',
        period_id: '', teacher_id: '', days_of_week: [],
    });
    const { data, setData, errors, processing, clearErrors } = form;
    const set = (k) => (v) => { clearErrors(k); setData(k, v); };
    const toggleDay = (d) => setData('days_of_week', data.days_of_week.includes(d) ? data.days_of_week.filter((x) => x !== d) : [...data.days_of_week, d].sort());
    const isAsp = data.category === 'asp';
    const teachersHere = teachers.filter((t2) => !data.branch_id || String(t2.branch_id) === data.branch_id);
    const [dismissed, setDismissed] = useState(false);
    useEffect(() => { if (saved) setDismissed(false); }, [saved]);

    const branch = branches.find((b) => String(b.id) === data.branch_id);
    const year = academicYears.find((y) => String(y.id) === data.academic_year_id);
    const here = existing.filter((s) => String(s.branch_id) === data.branch_id && String(s.academic_year_id) === data.academic_year_id);
    const nameTrim = data.name.trim();
    const duplicate = nameTrim !== '' && here.some((s) => s.name.trim().toLowerCase() === nameTrim.toLowerCase());
    const amountOk = AMOUNT.test(norm(data.amount));
    const amountNum = amountOk ? Number(norm(data.amount)) : null;
    const freqLabel = (f) => t(`fee.freq_${f}`);

    const steps = [
        { key: 'where', label: t('svc.step_where'), description: t('svc.step_where_desc'), fields: ['branch_id', 'academic_year_id'], isValid: () => Boolean(data.branch_id && data.academic_year_id), whyBlocked: t('svc.why_where') },
        { key: 'what', label: t('svc.step_what'), description: t('svc.step_what_desc'), fields: ['name', 'category'], isValid: () => nameTrim !== '' && KINDS.includes(data.category), whyBlocked: t('svc.why_what') },
        ...(isAsp ? [{ key: 'schedule', label: t('svc.step_schedule'), description: t('svc.step_schedule_desc'), fields: ['period_id', 'teacher_id', 'days_of_week'], isValid: () => true }] : []),
        { key: 'price', label: t('svc.step_price'), description: t('svc.step_price_desc'), fields: ['amount', 'frequency'], isValid: () => amountOk && FREQS.includes(data.frequency), whyBlocked: t('svc.why_price') },
        { key: 'review', label: t('svc.step_review'), description: t('svc.step_review_desc') },
    ];

    const wizard = useWizard({
        steps,
        errors,
        onSubmit: () => {
            form.transform((d) => ({
                branch_id: d.branch_id, academic_year_id: d.academic_year_id, name: nameTrim, category: d.category, amount: norm(d.amount), frequency: d.frequency,
                period_id: isAsp && d.period_id ? d.period_id : null,
                teacher_id: isAsp && d.teacher_id ? d.teacher_id : null,
                days_of_week: isAsp ? d.days_of_week : [],
            }));
            form.post(route('optional-services.store'), { preserveScroll: true });
        },
    });
    const key = wizard.step.key;
    const stepOf = (k) => steps.findIndex((s) => s.key === k);

    const title = t('svc.title_add');
    const header = <h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">{title}</h2>;
    const back = <BackLink href={route('optional-services.index')}>{t('svc.back')}</BackLink>;

    if (saved && !dismissed) {
        return (
            <AuthenticatedLayout header={header}>
                <Page title={title} width="default">
                    <SuccessPanel
                        title={t('svc.saved_title', { name: saved.name })}
                        details={<p className="text-base text-emerald-950 dark:text-emerald-50">{t('svc.saved_note')}</p>}
                        actions={
                            <div className="flex w-full flex-col gap-3">
                                <Button fullWidth onClick={() => { setDismissed(true); setData((d) => ({ ...d, name: '', category: '', amount: '', frequency: '' })); wizard.goTo(stepOf('what')); }}>{t('svc.add_another', { year: saved.academic_year })}</Button>
                                <Button variant="secondary" fullWidth onClick={() => { setDismissed(true); setData((d) => ({ ...d, academic_year_id: '', name: '', category: '', amount: '', frequency: '' })); wizard.reset(); }}>{t('svc.add_other_year')}</Button>
                                <Button as={Link} href={route('optional-services.index')} variant="quiet" fullWidth>{t('svc.see_all')}</Button>
                            </div>
                        }
                    >
                        {t('svc.saved_line', { name: saved.name, amount: money(saved.amount), frequency: freqLabel(saved.frequency), year: saved.academic_year })}
                    </SuccessPanel>
                </Page>
            </AuthenticatedLayout>
        );
    }

    return (
        <AuthenticatedLayout header={header}>
            <Page title={title} description={t('svc.desc_add')} width="default" back={back}>
                <Card>
                    <WizardShell wizard={wizard} finalLabel={t('svc.save_button')} processing={processing}>
                        {key === 'where' && (
                            <div className="space-y-6">
                                {branches.length > 1 && <ChoiceGroup heading={t('svc.q_branch')} value={data.branch_id} onChange={set('branch_id')} options={branches.map((b) => ({ value: String(b.id), label: b.name }))} />}
                                <ChoiceGroup
                                    heading={t('svc.q_year')}
                                    value={data.academic_year_id}
                                    onChange={set('academic_year_id')}
                                    options={academicYears.map((y) => ({ value: String(y.id), label: y.name, hint: y.is_current ? t('svc.year_current') : undefined }))}
                                />
                                {data.branch_id && data.academic_year_id && (
                                    <div className="rounded-xl bg-stone-50 p-4 dark:bg-stone-900/40" data-testid="existing">
                                        <p className="text-lg font-semibold text-stone-900 dark:text-stone-100">{t('svc.existing_title', { year: year?.name })}</p>
                                        <p className="mt-1 text-base text-stone-700 dark:text-stone-300">{here.length === 0 ? t('svc.existing_none') : here.map((s) => s.name).sort().join(', ')}</p>
                                    </div>
                                )}
                            </div>
                        )}

                        {key === 'what' && (
                            <div className="space-y-6">
                                <Field id="svc_name" label={t('svc.name_label')} hint={t('svc.name_hint')} error={errors.name}>
                                    <Input value={data.name} onChange={(e) => set('name')(e.target.value)} autoFocus autoComplete="off" maxLength={255} />
                                </Field>
                                {duplicate && <Alert tone="warning" title={t('svc.dup_title', { name: nameTrim })}>{t('svc.dup_body')}</Alert>}
                                <div>
                                    <ChoiceGroup
                                        heading={t('svc.q_kind')}
                                        value={data.category}
                                        onChange={set('category')}
                                        options={KINDS.map((k) => ({ value: k, label: t(`svc.kind_${k}`), hint: t(`svc.kind_${k}_hint`) }))}
                                    />
                                    {errors.category && <p className="mt-2 text-sm font-medium text-red-700">{errors.category}</p>}
                                </div>
                            </div>
                        )}

                        {key === 'schedule' && (
                            <div className="space-y-6">
                                <Field id="svc_period" label={t('svc.period_label')} hint={t('svc.period_hint')} optional error={errors.period_id}>
                                    <Select value={data.period_id} onChange={(e) => set('period_id')(e.target.value)}>
                                        <option value="">{t('svc.period_none')}</option>
                                        {periods.map((p) => <option key={p.id} value={p.id}>{p.label}</option>)}
                                    </Select>
                                </Field>
                                <Field id="svc_teacher" label={t('svc.teacher_label')} hint={t('svc.teacher_hint')} optional error={errors.teacher_id}>
                                    <Select value={data.teacher_id} onChange={(e) => set('teacher_id')(e.target.value)}>
                                        <option value="">{t('svc.teacher_none')}</option>
                                        {teachersHere.map((t2) => <option key={t2.id} value={t2.id}>{t2.name}</option>)}
                                    </Select>
                                </Field>
                                <div>
                                    <p className="mb-2 text-base font-medium text-stone-900 dark:text-stone-100">{t('svc.days_label')}</p>
                                    <div className="flex flex-wrap gap-2" role="group" aria-label={t('svc.days_label')}>
                                        {WEEKDAYS.map((d) => (
                                            <button
                                                key={d}
                                                type="button"
                                                aria-pressed={data.days_of_week.includes(d)}
                                                onClick={() => toggleDay(d)}
                                                className={`min-h-[44px] rounded-xl border-2 px-3 text-base font-semibold ${data.days_of_week.includes(d) ? 'border-brand-600 bg-brand-600 text-white' : 'border-stone-300 bg-white text-stone-900 dark:border-stone-600 dark:bg-stone-800 dark:text-stone-100'}`}
                                            >
                                                {t(`svc.day_${d}`)}
                                            </button>
                                        ))}
                                    </div>
                                </div>
                            </div>
                        )}

                        {key === 'price' && (
                            <div className="space-y-6">
                                <Field id="svc_amount" label={t('svc.amount_label')} hint={t('svc.amount_hint')} error={data.amount !== '' && !amountOk ? t('svc.err_amount') : errors.amount}>
                                    <Input inputMode="decimal" autoComplete="off" value={data.amount} onChange={(e) => set('amount')(e.target.value)} />
                                </Field>
                                <div>
                                    <ChoiceGroup heading={t('svc.q_freq')} value={data.frequency} onChange={set('frequency')} options={FREQS.map((f) => ({ value: f, label: freqLabel(f), hint: t(`fee.freq_${f}_hint`) }))} />
                                    {errors.frequency && <p className="mt-2 text-sm font-medium text-red-700">{errors.frequency}</p>}
                                </div>
                            </div>
                        )}

                        {key === 'review' && (
                            <div className="space-y-5">
                                <div className="rounded-2xl border-2 border-brand-300 bg-brand-50 p-5 dark:border-brand-700 dark:bg-brand-900/20" data-testid="headline">
                                    <p className="text-2xl font-bold text-stone-900 dark:text-stone-100">{nameTrim} — {money(amountNum ?? 0)} ETB</p>
                                    <p className="mt-1 text-lg text-stone-800 dark:text-stone-200">{data.frequency ? freqLabel(data.frequency) : ''} · {year?.name}{branches.length > 1 ? ` · ${branch?.name}` : ''}</p>
                                </div>
                                <ReviewList
                                    onEdit={wizard.goTo}
                                    rows={[
                                        ...(branches.length > 1 ? [{ label: t('svc.r_branch'), value: branch?.name ?? '', step: stepOf('where') }] : []),
                                        { label: t('svc.r_year'), value: year?.name ?? '', step: stepOf('where') },
                                        { label: t('svc.r_name'), value: nameTrim, step: stepOf('what') },
                                        { label: t('svc.r_kind'), value: data.category ? t(`svc.kind_${data.category}`) : '', step: stepOf('what') },
                                        ...(isAsp ? [{
                                            label: t('svc.r_schedule'),
                                            value: [
                                                periods.find((p) => String(p.id) === String(data.period_id))?.label,
                                                teachersHere.find((t2) => String(t2.id) === String(data.teacher_id))?.name,
                                                data.days_of_week.map((d) => t(`svc.day_${d}`)).join(', ') || null,
                                            ].filter(Boolean).join(' · ') || t('svc.r_schedule_none'),
                                            step: stepOf('schedule'),
                                        }] : []),
                                        { label: t('svc.r_amount'), value: `${money(amountNum ?? 0)} ETB`, step: stepOf('price') },
                                        { label: t('svc.r_freq'), value: data.frequency ? freqLabel(data.frequency) : '', step: stepOf('price') },
                                    ]}
                                />
                                {amountNum === 0 && <Alert tone="warning" title={t('svc.warn_zero_title')}>{t('svc.warn_zero_body')}</Alert>}
                                {duplicate && <Alert tone="warning" title={t('svc.dup_title', { name: nameTrim })}>{t('svc.dup_body')}</Alert>}
                                <p className="text-base text-stone-700 dark:text-stone-300">{t('svc.review_note')}</p>
                            </div>
                        )}
                    </WizardShell>
                </Card>
            </Page>
        </AuthenticatedLayout>
    );
}
