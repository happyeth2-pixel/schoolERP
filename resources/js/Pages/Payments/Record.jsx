import { Alert, BackLink, Button, Card, CheckRow, ChoiceGroup, Field, FieldError, Input, Page, ReviewList, TextArea, WizardShell, useWizard } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Link, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';

// "Record payment received": staff took money at the school (cash, or a bank / mobile-money slip was shown) and write
// it down, in three short questions: HOW it was paid, HOW MUCH and by whom, then a check that reads it back before
// "Record payment received". The parent needs no account for any of this. Nothing here decides anything: the server
// holds the same rules as for a parent's proof (nobody pays more than the balance, a waived bill takes nothing, the
// invoice is locked, the receipt number is issued last) and only accepts people who hold verify-payments in this branch.

const AMOUNT = /^\d{1,8}(\.\d{1,2})?$/;
const norm = (s) => String(s ?? '').trim().replace(',', '.');
const money = (n) => Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const METHODS = ['cash', 'bank_transfer', 'mobile_money', 'other'];
const MAX_BYTES = 5 * 1024 * 1024;

function BreakdownRow({ label, value, testid, strong = false, big = false }) {
    return (
        <div className={`flex items-baseline justify-between gap-4 px-4 py-3 ${big ? 'bg-indigo-50 dark:bg-indigo-900/20' : ''}`}>
            <dt className={`text-base ${big ? 'font-bold text-gray-900 dark:text-gray-100' : 'text-gray-700 dark:text-gray-300'}`}>{label}</dt>
            <dd className={`${big ? 'text-2xl font-bold' : strong ? 'text-xl font-bold' : 'text-lg font-semibold'} text-gray-900 dark:text-gray-100`} data-testid={testid}>{value}</dd>
        </div>
    );
}

export default function Record({ invoice, student, guardians, waiting }) {
    const { t } = useTranslation();
    const form = useForm({ method: '', amount: '', paid_by_name: '', reference_number: '', note: '', confirm_duplicate: false, proof: null });
    const { data, setData, errors, processing, clearErrors } = form;
    const [fileError, setFileError] = useState(null);
    const set = (k) => (v) => { clearErrors(k); setData(k, v); };

    const cash = data.method === 'cash';
    const amountOk = AMOUNT.test(norm(data.amount)) && Number(norm(data.amount)) > 0;
    const amountNum = amountOk ? Number(norm(data.amount)) : 0;
    const over = amountOk && amountNum > invoice.balance + 0.001;
    const referenceOk = cash || data.reference_number.trim() !== '';
    const noteOk = data.method !== 'other' || data.note.trim() !== '';
    const duplicate = errors.duplicate_reference;
    const payerOk = data.paid_by_name.trim().length >= 2;
    const left = Math.max(0, Math.round((invoice.balance - amountNum) * 100) / 100);
    const preview = useMemo(() => (data.proof && data.proof.type.startsWith('image/') ? URL.createObjectURL(data.proof) : null), [data.proof]);

    const steps = [
        { key: 'how', label: t('rec.step_how'), description: t('rec.step_how_desc'), fields: ['method'], isValid: () => METHODS.includes(data.method), whyBlocked: t('rec.why_how') },
        {
            key: 'details', label: t('rec.step_details'), description: t('rec.step_details_desc'), fields: ['amount', 'paid_by_name', 'reference_number', 'proof'],
            isValid: () => amountOk && !over && payerOk && referenceOk && noteOk && fileError === null,
            whyBlocked: !amountOk ? t('rec.why_amount') : over ? t('rec.err_over', { balance: money(invoice.balance) }) : !payerOk ? t('rec.why_payer') : !referenceOk ? t('rec.why_reference') : !noteOk ? t('rec.note_required') : t('rec.file_error'),
        },
        // a reference that was used before must be confirmed as a different payment before it can be recorded
        { key: 'review', label: t('rec.step_review'), description: t('rec.step_review_desc'), isValid: () => !duplicate || data.confirm_duplicate, whyBlocked: t('rec.dup_why') },
    ];

    const wizard = useWizard({
        steps,
        errors,
        onSubmit: () => {
            form.transform((d) => ({
                method: d.method, amount: norm(d.amount), paid_by_name: d.paid_by_name.trim(), reference_number: d.method === 'cash' ? '' : d.reference_number.trim(), note: d.note.trim(), confirm_duplicate: d.confirm_duplicate ? 1 : 0,
                ...(d.proof ? { proof: d.proof } : {}),
            }));
            form.post(route('payments.record', invoice.id), { forceFormData: true, preserveScroll: true });
        },
    });
    const key = wizard.step.key;
    const stepOf = (k) => steps.findIndex((s) => s.key === k);

    const chooseFile = (file) => {
        clearErrors('proof');
        setFileError(null);
        if (!file) return;
        const ext = file.name.split('.').pop().toLowerCase();
        if (!['jpg', 'jpeg', 'png', 'pdf'].includes(ext)) { setData('proof', null); setFileError(t('rec.file_type')); return; }
        if (file.size > MAX_BYTES) { setData('proof', null); setFileError(t('rec.file_size')); return; }
        setData('proof', file);
    };

    const layoutHeader = <h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">{t('rec.title')}</h2>;
    const back = <BackLink href={route('invoices.show', invoice.id)}>{t('rec.back_to_bill')}</BackLink>;
    const methodLabel = (m) => t(`verify.method_${m}`);

    return (
        <AuthenticatedLayout header={layoutHeader}>
            <Page title={t('rec.title')} description={t('rec.desc')} width="default" back={back}>
                <Card data-testid="bill-summary">
                    <p className="text-2xl font-bold text-gray-900 dark:text-gray-100" data-testid="bill-student">{student.name}</p>
                    <p className="text-base text-gray-700 dark:text-gray-300" data-testid="bill-class">{student.class ?? t('find.no_class')} · {t('find.id_number', { id: student.student_id_number })}</p>
                    <p className="text-base text-gray-700 dark:text-gray-300" data-testid="bill-invoice">{t('rec.invoice_word')} {invoice.number}{invoice.period_label ? ` · ${invoice.period_label}` : ''}</p>
                    <dl className="mt-4 divide-y divide-gray-200 rounded-xl border-2 border-gray-200 bg-white dark:divide-gray-700 dark:border-gray-700 dark:bg-gray-800" data-testid="breakdown">
                        <BreakdownRow label={t('rec.bd_total')} value={`${money(invoice.total)} ETB`} testid="bd-total" />
                        <BreakdownRow label={t('rec.bd_paid')} value={`${money(invoice.collected)} ETB`} testid="bd-paid" />
                        <BreakdownRow label={t('rec.bd_this')} value={amountOk && !over ? `${money(amountNum)} ETB` : '—'} testid="bd-this" strong={amountOk && !over} />
                        <BreakdownRow label={amountOk && !over ? t('rec.bd_left_after') : t('rec.bd_left')} value={`${money(amountOk && !over ? left : invoice.balance)} ETB`} testid="bd-left" big />
                    </dl>
                    {amountOk && !over && (
                        <p className={`mt-3 text-base font-semibold ${left === 0 ? 'text-emerald-800 dark:text-emerald-300' : 'text-amber-900 dark:text-amber-300'}`} data-testid="bd-note">
                            {left === 0 ? t('rec.bd_full') : t('rec.bd_partial', { left: money(left) })}
                        </p>
                    )}
                </Card>

                {waiting.length > 0 && (
                    <div data-testid="waiting-proof">
                        <Alert tone="warning" title={t('rec.waiting_title')}>
                            <p>{t('rec.waiting_body')}</p>
                            <ul className="mt-2 space-y-1">
                                {waiting.map((w) => (
                                    <li key={w.id}>{money(w.amount)} ETB · {methodLabel(w.method)}{w.reference_number ? ` · ${w.reference_number}` : ''} · {w.by}, {w.when} · <Link className="font-semibold underline" href={w.review_url}>{t('rec.waiting_open')}</Link></li>
                                ))}
                            </ul>
                        </Alert>
                    </div>
                )}

                <Card>
                    <WizardShell wizard={wizard} finalLabel={t('rec.confirm_button')} processing={processing}>
                        {key === 'how' && (
                            <ChoiceGroup
                                heading={t('rec.how_question')}
                                value={data.method}
                                onChange={set('method')}
                                options={METHODS.map((m) => ({ value: m, label: methodLabel(m), hint: t(`rec.how_${m}`) }))}
                            />
                        )}

                        {key === 'details' && (
                            <div className="space-y-6">
                                <Field id="rec_amount" label={t('rec.amount')} hint={t('rec.amount_hint', { balance: money(invoice.balance) })} error={over ? t('rec.err_over', { balance: money(invoice.balance) }) : errors.amount}>
                                    <Input inputMode="decimal" value={data.amount} onChange={(e) => set('amount')(e.target.value)} autoFocus autoComplete="off" />
                                </Field>
                                <Button variant="quiet" size="md" onClick={() => set('amount')(String(invoice.balance))}>{t('rec.pay_full', { amount: money(invoice.balance) })}</Button>

                                <div>
                                    <Field id="rec_payer" label={t('rec.payer')} hint={t('rec.payer_hint')} error={errors.paid_by_name}>
                                        <Input value={data.paid_by_name} onChange={(e) => set('paid_by_name')(e.target.value)} autoComplete="off" maxLength={120} />
                                    </Field>
                                    {guardians.length > 0 && (
                                        <div className="mt-2 flex flex-wrap gap-2" data-testid="payer-suggestions">
                                            {guardians.map((g) => <Button key={g.name} variant="secondary" size="md" onClick={() => set('paid_by_name')(g.name)}>{g.name} ({g.relationship})</Button>)}
                                        </div>
                                    )}
                                </div>

                                {!cash && (
                                    <Field id="rec_reference" label={t('rec.reference')} hint={t('rec.reference_hint')} error={errors.reference_number}>
                                        <Input value={data.reference_number} onChange={(e) => { clearErrors('duplicate_reference'); setData((d) => ({ ...d, confirm_duplicate: false })); set('reference_number')(e.target.value); }} autoComplete="off" maxLength={255} />
                                    </Field>
                                )}

                                <Field id="rec_note" label={t('rec.note')} hint={t('rec.note_hint')} optional={data.method !== 'other'} error={errors.note}>
                                    <TextArea rows={2} maxLength={500} value={data.note} onChange={(e) => set('note')(e.target.value)} />
                                </Field>

                                <div>
                                    <p className="text-base font-medium text-gray-900 dark:text-gray-100">{t('rec.slip')} <span className="font-normal text-gray-600 dark:text-gray-400">({t('common.optional', { defaultValue: 'Optional' })})</span></p>
                                    <p className="mt-0.5 text-sm text-gray-600 dark:text-gray-400">{t('rec.slip_hint')}</p>
                                    {preview && <img src={preview} alt={t('rec.slip')} className="mt-2 max-h-40 rounded-lg border-2 border-gray-200" />}
                                    <label className="mt-2 inline-flex min-h-[44px] cursor-pointer items-center rounded-xl border-2 border-gray-400 bg-white px-4 py-2 text-sm font-semibold text-gray-900 hover:bg-gray-50 dark:border-gray-500 dark:bg-gray-800 dark:text-gray-100">
                                        {data.proof ? t('rec.slip_change') : t('rec.slip_choose')}
                                        <input type="file" accept=".jpg,.jpeg,.png,.pdf" className="sr-only" data-testid="slip-input" onChange={(e) => chooseFile(e.target.files[0])} />
                                    </label>
                                    {data.proof && <span className="ml-3 break-words text-sm text-gray-800 dark:text-gray-200">{data.proof.name}</span>}
                                    <FieldError message={fileError ?? errors.proof} />
                                </div>
                            </div>
                        )}

                        {key === 'review' && (
                            <div className="space-y-5">
                                <div className="space-y-2 rounded-2xl border-2 border-indigo-300 bg-indigo-50 p-5 dark:border-indigo-700 dark:bg-indigo-900/20" data-testid="headline">
                                    <p className="text-xl text-gray-900 dark:text-gray-100" data-testid="confirm-student">{t('rec.review_student')}: <strong>{student.name}</strong></p>
                                    <p className="text-xl text-gray-900 dark:text-gray-100" data-testid="confirm-payment">{t('rec.review_payment')}: <strong className="text-3xl">{money(amountNum)} ETB</strong></p>
                                    <p className="text-xl text-gray-900 dark:text-gray-100" data-testid="confirm-left">{t('rec.review_left')}: <strong className="text-3xl">{money(left)} ETB</strong></p>
                                </div>
                                <ReviewList
                                    onEdit={wizard.goTo}
                                    rows={[
                                        { label: t('rec.r_how'), value: methodLabel(data.method), step: stepOf('how') },
                                        { label: t('rec.r_payer'), value: data.paid_by_name.trim(), step: stepOf('details') },
                                        ...(cash ? [] : [{ label: t('rec.r_reference'), value: data.reference_number.trim(), step: stepOf('details') }]),
                                        ...(data.note.trim() ? [{ label: t('rec.r_note'), value: data.note.trim(), step: stepOf('details') }] : []),
                                        { label: t('rec.r_slip'), value: data.proof?.name ?? t('rec.r_none'), step: stepOf('details') },
                                        { label: t('rec.r_left'), value: left === 0 ? t('rec.r_paid_off') : `${money(left)} ETB`, step: undefined },
                                    ]}
                                />
                                {duplicate && (
                                    <div className="space-y-3" data-testid="duplicate-warning">
                                        <Alert tone="warning" title={t('rec.dup_title')}>{duplicate}</Alert>
                                        <CheckRow checked={data.confirm_duplicate} onChange={(v) => setData('confirm_duplicate', v)} label={t('rec.dup_confirm')} id="confirm_duplicate" />
                                    </div>
                                )}
                                <p className="text-base text-gray-700 dark:text-gray-300">{t('rec.review_note')}</p>
                                {errors.amount && <Alert tone="danger" title={t('rec.error_title')}><span data-testid="record-error">{errors.amount}</span></Alert>}
                            </div>
                        )}
                    </WizardShell>
                </Card>
            </Page>
        </AuthenticatedLayout>
    );
}
