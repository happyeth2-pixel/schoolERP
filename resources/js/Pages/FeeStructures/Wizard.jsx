import { Alert, BackLink, Button, Card, ChoiceGroup, Field, Input, Page, ReviewList, SuccessPanel, WizardShell, useWizard } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useForm } from '@inertiajs/react';
import { Link } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';

// Adding or changing a fee, as three short questions: WHERE it applies (grade + school year), WHAT it is (name,
// amount, how often), then a check that reads the whole thing back before "Save fee structure". Changing a fee is
// the same page without the first question, because the backend only lets a fee's name, amount and frequency
// change (its grade, year and branch are fixed). Nothing here calculates money: it sends the same fields to the
// same endpoints (store / update), which keep every rule -- branch and school scope, the amount limits, and (new)
// one fee of a given name per grade and year. Nothing on this page is optional, so nothing says it can be left empty.

const AMOUNT = /^\d{1,8}(\.\d{1,2})?$/;
const norm = (s) => String(s ?? '').trim().replace(',', '.');
const money = (n) => Number(n).toLocaleString(undefined, { maximumFractionDigits: 2 });
const FREQS = ['annual', 'termly', 'monthly'];

export default function Wizard({ branches, grades, academicYears, existing, termCounts, nameSuggestions, saved, fee }) {
    const { t } = useTranslation();
    const editing = Boolean(fee);

    const form = useForm({
        branch_id: fee ? String(fee.branch_id) : branches.length === 1 ? String(branches[0].id) : '',
        grade_id: fee ? String(fee.grade_id) : '',
        academic_year_id: fee ? String(fee.academic_year_id) : '',
        name: fee?.name ?? '',
        amount: fee ? String(fee.amount) : '',
        frequency: fee?.frequency ?? '',
    });
    const { data, setData, errors, processing, clearErrors } = form;
    const [dismissed, setDismissed] = useState(false);
    useEffect(() => { if (saved) setDismissed(false); }, [saved]);   // a new save shows its own confirmation
    const set = (k) => (v) => { clearErrors(k); setData(k, v); };

    const grade = grades.find((g) => String(g.id) === data.grade_id);
    const year = academicYears.find((y) => String(y.id) === data.academic_year_id);
    const branch = branches.find((b) => String(b.id) === data.branch_id);
    const here = existing.filter((f) => String(f.branch_id) === data.branch_id && String(f.grade_id) === data.grade_id && String(f.academic_year_id) === data.academic_year_id);
    const countFor = (g) => existing.filter((f) => String(f.branch_id) === data.branch_id && f.grade_id === g.id && String(f.academic_year_id) === data.academic_year_id).length;

    const nameTrim = data.name.trim();
    const duplicate = here.some((f) => f.id !== fee?.id && f.name.trim().toLowerCase() === nameTrim.toLowerCase());
    const amountOk = AMOUNT.test(norm(data.amount));
    const amountNum = amountOk ? Number(norm(data.amount)) : null;
    const noTerms = data.frequency === 'termly' && !(termCounts?.[data.academic_year_id] > 0);
    const freqLabel = (f) => t(`fee.freq_${f}`);
    const suggestions = [...new Set([...(nameSuggestions ?? []), 'Tuition', 'Registration', 'Books'])].slice(0, 6);

    const steps = [
        ...(editing ? [] : [{
            key: 'who', label: t('fee.step_who'), description: t('fee.step_who_desc'), fields: ['branch_id', 'grade_id', 'academic_year_id'],
            isValid: () => Boolean(data.branch_id && data.grade_id && data.academic_year_id), whyBlocked: t('fee.why_who'),
        }]),
        {
            key: 'fee', label: t('fee.step_fee'), description: t('fee.step_fee_desc'), fields: ['name', 'amount', 'frequency'],
            isValid: () => nameTrim !== '' && !duplicate && amountOk && FREQS.includes(data.frequency), whyBlocked: t('fee.why_fee'),
        },
        { key: 'review', label: t('fee.step_review'), description: t('fee.step_review_desc') },
    ];

    const wizard = useWizard({
        steps,
        errors,
        onSubmit: () => {
            const payload = { name: nameTrim, amount: norm(data.amount), frequency: data.frequency };
            form.transform((d) => (editing ? payload : { branch_id: d.branch_id, grade_id: d.grade_id, academic_year_id: d.academic_year_id, ...payload }));
            if (editing) form.patch(route('fee-structures.update', fee.id), { preserveScroll: true });
            else form.post(route('fee-structures.store'), { preserveScroll: true });
        },
    });
    const key = wizard.step.key;
    const indexOf = (k) => steps.findIndex((s) => s.key === k);

    const title = editing ? t('fee.title_edit') : t('fee.title_add');
    const layoutHeader = <h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">{title}</h2>;
    const back = <BackLink href={route('fee-structures.index')}>{t('fee.back_to_fees')}</BackLink>;

    // ------------------------------------------------------------------ saved
    if (saved && !dismissed) {
        return (
            <AuthenticatedLayout header={layoutHeader}>
                <Page title={title} width="default">
                    <SuccessPanel
                        title={saved.action === 'added' ? t('fee.saved_title') : t('fee.changed_title')}
                        details={<p className="text-base text-emerald-950 dark:text-emerald-50">{t('fee.saved_note')}</p>}
                        actions={
                            <div className="flex w-full flex-col gap-3">
                                {saved.action === 'added' && (
                                    <Button fullWidth onClick={() => { setDismissed(true); setData((d) => ({ ...d, name: '', amount: '', frequency: '' })); wizard.goTo(indexOf('fee')); }}>{t('fee.add_another', { grade: saved.grade })}</Button>
                                )}
                                {saved.action === 'added' && (
                                    <Button variant="secondary" fullWidth onClick={() => { setDismissed(true); setData((d) => ({ ...d, grade_id: '', name: '', amount: '', frequency: '' })); wizard.reset(); }}>{t('fee.add_other_grade')}</Button>
                                )}
                                <Button as={Link} href={route('fee-structures.index')} variant={saved.action === 'added' ? 'quiet' : 'primary'} fullWidth>{t('fee.see_fees')}</Button>
                            </div>
                        }
                    >
                        {t('fee.summary_line', { grade: saved.grade, name: saved.name, amount: money(saved.amount), frequency: freqLabel(saved.frequency), year: saved.academic_year })}
                    </SuccessPanel>
                </Page>
            </AuthenticatedLayout>
        );
    }

    const reviewRows = [
        { label: t('fee.r_grade'), value: grade?.name ?? '', step: editing ? undefined : indexOf('who') },
        { label: t('fee.r_year'), value: year?.name ?? '', step: editing ? undefined : indexOf('who') },
        ...(branches.length > 1 ? [{ label: t('fee.r_branch'), value: branch?.name ?? '', step: editing ? undefined : indexOf('who') }] : []),
        { label: t('fee.r_fee'), value: nameTrim + (editing && fee.name !== nameTrim ? ` (${t('fee.r_was', { value: fee.name })})` : ''), step: indexOf('fee') },
        { label: t('fee.r_amount'), value: `${money(amountNum ?? 0)} ETB` + (editing && Number(fee.amount) !== amountNum ? ` (${t('fee.r_was', { value: `${money(fee.amount)} ETB` })})` : ''), step: indexOf('fee') },
        { label: t('fee.r_freq'), value: data.frequency ? freqLabel(data.frequency) : '', step: indexOf('fee') },
    ];

    return (
        <AuthenticatedLayout header={layoutHeader}>
            <Page title={title} description={editing ? t('fee.desc_edit') : t('fee.desc_add')} width="default" back={back}>
                {(errors.name && key === 'review') && <Alert tone="danger" title={t('fee.error_title')}>{errors.name}</Alert>}
                <Card>
                    {editing && (
                        <div className="mb-5 rounded-xl bg-stone-50 p-4 dark:bg-stone-900/40" data-testid="locked">
                            <p className="text-lg font-semibold text-stone-900 dark:text-stone-100">{fee.grade} · {fee.academic_year}{branches.length > 1 ? ` · ${fee.branch}` : ''}</p>
                            <p className="text-base text-stone-700 dark:text-stone-300">{t('fee.edit_locked')}</p>
                        </div>
                    )}
                    <WizardShell wizard={wizard} finalLabel={editing ? t('fee.save_changes') : t('fee.save_button')} processing={processing}>
                        {key === 'who' && (
                            <div className="space-y-6">
                                {branches.length > 1 && (
                                    <ChoiceGroup label={t('fee.branch')} value={data.branch_id} onChange={(v) => { set('branch_id')(v); setData('grade_id', ''); }} options={branches.map((b) => ({ value: String(b.id), label: b.name }))} />
                                )}
                                <ChoiceGroup
                                    label={t('fee.year')}
                                    value={data.academic_year_id}
                                    onChange={set('academic_year_id')}
                                    options={academicYears.map((y) => ({ value: String(y.id), label: y.name, hint: y.is_current ? t('fee.year_current') : undefined }))}
                                />
                                {data.branch_id && (
                                    <div>
                                        <p className="mb-2 text-lg font-semibold text-stone-900 dark:text-stone-100">{t('fee.grade')}</p>
                                        <div role="radiogroup" aria-label={t('fee.grade')} className="grid grid-cols-2 gap-3 sm:grid-cols-3" data-testid="grade-grid">
                                            {grades.map((g) => {
                                                const selected = String(g.id) === data.grade_id;
                                                const n = countFor(g);
                                                return (
                                                    <button key={g.id} type="button" role="radio" aria-checked={selected} onClick={() => set('grade_id')(String(g.id))}
                                                        className={`flex min-h-[72px] flex-col items-center justify-center rounded-xl border-2 px-3 py-2 text-center transition focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 ${selected ? 'border-brand-600 bg-brand-600 text-white' : 'border-stone-300 bg-white text-stone-900 hover:border-brand-400 dark:border-stone-600 dark:bg-stone-800 dark:text-stone-100'}`}>
                                                        <span className="text-lg font-bold">{g.name}</span>
                                                        {data.academic_year_id && <span className={`text-sm ${selected ? 'text-brand-100' : 'text-stone-600 dark:text-stone-400'}`}>{n === 0 ? t('fee.grade_fees_zero') : t('fee.grade_fees_n', { count: n })}</span>}
                                                    </button>
                                                );
                                            })}
                                        </div>
                                    </div>
                                )}
                                {grade && year && (
                                    <div className="rounded-xl bg-stone-50 p-4 dark:bg-stone-900/40" data-testid="existing">
                                        <p className="text-lg font-semibold text-stone-900 dark:text-stone-100">{t('fee.existing_title', { grade: grade.name, year: year.name })}</p>
                                        {here.length === 0 ? <p className="mt-1 text-base text-stone-700 dark:text-stone-300">{t('fee.existing_none')}</p> : (
                                            <>
                                                <ul className="mt-2 space-y-1 text-base text-stone-800 dark:text-stone-200">
                                                    {here.map((f) => <li key={f.id} className="flex justify-between gap-3"><span>{f.name}</span><span>{money(f.amount)} ETB · {freqLabel(f.frequency)}</span></li>)}
                                                </ul>
                                                <p className="mt-2 text-sm text-stone-600 dark:text-stone-400">{t('fee.existing_tip')}</p>
                                            </>
                                        )}
                                    </div>
                                )}
                            </div>
                        )}

                        {key === 'fee' && (
                            <div className="space-y-6">
                                <Field id="fee_name" label={t('fee.name_label')} hint={t('fee.name_hint')} error={duplicate ? t('fee.err_dup', { name: nameTrim }) : errors.name}>
                                    <Input value={data.name} onChange={(e) => set('name')(e.target.value)} autoFocus autoComplete="off" maxLength={255} />
                                </Field>
                                {!editing && (
                                    <div className="-mt-3">
                                        <p className="mb-1 text-sm text-stone-600 dark:text-stone-400">{t('fee.name_suggest')}</p>
                                        <div className="flex flex-wrap gap-2">
                                            {suggestions.map((s) => <button key={s} type="button" onClick={() => set('name')(s)} className="min-h-[44px] rounded-xl border-2 border-stone-300 bg-white px-3 text-base font-medium text-stone-900 hover:border-brand-400 dark:border-stone-600 dark:bg-stone-800 dark:text-stone-100">{s}</button>)}
                                        </div>
                                    </div>
                                )}
                                <Field id="fee_amount" label={t('fee.amount_label')} hint={t('fee.amount_hint')} error={data.amount !== '' && !amountOk ? t('fee.err_amount') : errors.amount}>
                                    <Input inputMode="decimal" autoComplete="off" value={data.amount} onChange={(e) => set('amount')(e.target.value)} />
                                </Field>
                                <div>
                                    <ChoiceGroup label={t('fee.freq_label')} value={data.frequency} onChange={set('frequency')} options={FREQS.map((f) => ({ value: f, label: freqLabel(f), hint: t(`fee.freq_${f}_hint`) }))} />
                                    {errors.frequency && <p className="mt-2 text-sm font-medium text-red-700">{errors.frequency}</p>}
                                </div>
                                {noTerms && <Alert tone="warning" title={t('fee.warn_terms_title')}>{t('fee.warn_terms_body')}</Alert>}
                            </div>
                        )}

                        {key === 'review' && (
                            <div className="space-y-4">
                                <div className="rounded-2xl border-2 border-brand-300 bg-brand-50 p-5 dark:border-brand-700 dark:bg-brand-900/20" data-testid="headline">
                                    <p className="text-2xl font-bold text-stone-900 dark:text-stone-100">{nameTrim} — {money(amountNum ?? 0)} ETB</p>
                                    <p className="mt-1 text-lg text-stone-800 dark:text-stone-200">{data.frequency ? freqLabel(data.frequency) : ''} · {grade?.name} · {year?.name}</p>
                                </div>
                                <ReviewList rows={reviewRows} onEdit={wizard.goTo} />
                                {amountNum === 0 && <Alert tone="warning" title={t('fee.warn_zero_title')}>{t('fee.warn_zero_body')}</Alert>}
                                {noTerms && <Alert tone="warning" title={t('fee.warn_terms_title')}>{t('fee.warn_terms_body')}</Alert>}
                                <p className="text-base text-stone-700 dark:text-stone-300">{t('fee.review_note')}</p>
                            </div>
                        )}
                    </WizardShell>
                </Card>
            </Page>
        </AuthenticatedLayout>
    );
}
