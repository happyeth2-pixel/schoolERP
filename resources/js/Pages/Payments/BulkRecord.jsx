import { Alert, BackLink, Button, Card, CheckRow, ChoiceGroup, Field, FieldError, Input, Page, ReviewList, TextArea, WizardShell, useWizard } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Link, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';

// "A family paid a lump sum covering several bills at once" -- one amount, split automatically across this
// student's own open bills, oldest period first, as far as it goes. Mirrors Payments/Record.jsx's own
// three-question shape (how / how much & by whom / check) so staff who already know that screen recognize
// this one immediately; the only real difference is the allocation preview on the review step, computed
// here with the exact same oldest-first rule StudentPaymentController::store() applies for real.

const AMOUNT = /^\d{1,8}(\.\d{1,2})?$/;
const norm = (s) => String(s ?? '').trim().replace(',', '.');
const money = (n) => Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const METHODS = ['cash', 'bank_transfer', 'mobile_money', 'other'];
const MAX_BYTES = 5 * 1024 * 1024;

/** Same oldest-first split StudentPaymentController::store() performs server-side -- shown here so staff
 *  see exactly which bills will be covered, and by how much, before confirming anything. */
function allocate(amount, invoices) {
    let remaining = Math.round(amount * 100) / 100;
    const rows = [];
    for (const inv of invoices) {
        if (remaining <= 0) break;
        const share = Math.round(Math.min(remaining, inv.balance) * 100) / 100;
        if (share <= 0) continue;
        rows.push({ ...inv, applied: share, leftAfter: Math.round((inv.balance - share) * 100) / 100 });
        remaining = Math.round((remaining - share) * 100) / 100;
    }
    return rows;
}

export default function BulkRecord({ student, guardians, invoices, totalOwed }) {
    const { t } = useTranslation();
    const form = useForm({ method: '', amount: '', paid_by_name: '', reference_number: '', note: '', confirm_duplicate: false, proof: null });
    const { data, setData, errors, processing, clearErrors } = form;
    const [fileError, setFileError] = useState(null);
    const set = (k) => (v) => { clearErrors(k); setData(k, v); };

    const cash = data.method === 'cash';
    const amountOk = AMOUNT.test(norm(data.amount)) && Number(norm(data.amount)) > 0;
    const amountNum = amountOk ? Number(norm(data.amount)) : 0;
    const over = amountOk && amountNum > totalOwed + 0.001;
    const noteOk = data.method !== 'other' || data.note.trim() !== '';
    const duplicate = errors.duplicate_reference;
    const payerOk = data.paid_by_name.trim().length === 0 || data.paid_by_name.trim().length >= 2;
    const allocation = useMemo(() => (amountOk && !over ? allocate(amountNum, invoices) : []), [amountOk, over, amountNum, invoices]);
    const leftOverall = Math.max(0, Math.round((totalOwed - amountNum) * 100) / 100);
    const preview = useMemo(() => (data.proof && data.proof.type.startsWith('image/') ? URL.createObjectURL(data.proof) : null), [data.proof]);

    const steps = [
        { key: 'how', label: 'How was it paid?', description: 'Choose how the money came in.', fields: ['method'], isValid: () => METHODS.includes(data.method), whyBlocked: 'Choose how it was paid.' },
        {
            key: 'details', label: 'How much, and by whom?', description: 'Check the amount, then who handed it over.', fields: ['amount', 'paid_by_name', 'reference_number', 'proof'],
            isValid: () => amountOk && !over && payerOk && noteOk && fileError === null,
            whyBlocked: !amountOk ? 'Type the amount received.' : over ? `This is more than everything owed (${money(totalOwed)} ETB).` : !payerOk ? 'Write a name, or leave it blank.' : !noteOk ? 'Write a short note.' : 'Check the file.',
        },
        { key: 'review', label: 'Check, then record', description: 'This is exactly what will be recorded.', isValid: () => !duplicate || data.confirm_duplicate, whyBlocked: 'Confirm the reference number is a different payment.' },
    ];

    const wizard = useWizard({
        steps,
        errors,
        onSubmit: () => {
            form.transform((d) => ({
                method: d.method, amount: norm(d.amount), paid_by_name: d.paid_by_name.trim(), reference_number: d.method === 'cash' ? '' : d.reference_number.trim(), note: d.note.trim(), confirm_duplicate: d.confirm_duplicate ? 1 : 0,
                ...(d.proof ? { proof: d.proof } : {}),
            }));
            form.post(route('students.record-payment.store', student.id), { forceFormData: true, preserveScroll: true });
        },
    });
    const key = wizard.step.key;
    const stepOf = (k) => steps.findIndex((s) => s.key === k);
    const methodLabel = (m) => t(`verify.method_${m}`);

    const chooseFile = (file) => {
        clearErrors('proof');
        setFileError(null);
        if (!file) return;
        const ext = file.name.split('.').pop().toLowerCase();
        if (!['jpg', 'jpeg', 'png', 'pdf'].includes(ext)) { setData('proof', null); setFileError('That file type is not allowed. Use a JPG, PNG or PDF.'); return; }
        if (file.size > MAX_BYTES) { setData('proof', null); setFileError('That file is too large (5MB limit).'); return; }
        setData('proof', file);
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">Record a Combined Payment</h2>}>
            <Page
                title="Record a Combined Payment"
                description="One amount, spread automatically across every bill this student currently owes, oldest first."
                width="default"
                back={<BackLink href={route('students.desk', student.id)}>Back to {student.name}</BackLink>}
            >
                <Card data-testid="bill-summary">
                    <p className="text-2xl font-bold text-stone-900 dark:text-stone-100">{student.name}</p>
                    <p className="text-base text-stone-700 dark:text-stone-300">{student.class ?? '—'} · {student.student_id_number}</p>
                    <dl className="mt-4 divide-y divide-stone-200 rounded-xl border-2 border-stone-200 bg-white dark:divide-stone-700 dark:border-stone-700 dark:bg-stone-800">
                        <div className="flex items-baseline justify-between gap-4 px-4 py-3">
                            <dt className="text-base text-stone-700 dark:text-stone-300">Total currently owed</dt>
                            <dd className="text-2xl font-bold text-stone-900 dark:text-stone-100">{money(totalOwed)} ETB</dd>
                        </div>
                    </dl>
                    <ul className="mt-3 space-y-1 text-sm text-stone-600 dark:text-stone-400">
                        {invoices.map((i) => <li key={i.id}>{i.period} ({i.number}) — {money(i.balance)} ETB</li>)}
                    </ul>
                </Card>

                <Card className="mt-6">
                    <WizardShell wizard={wizard} finalLabel="Record this payment" processing={processing}>
                        {key === 'how' && (
                            <ChoiceGroup
                                heading="How was the money paid?"
                                value={data.method}
                                onChange={set('method')}
                                options={METHODS.map((m) => ({ value: m, label: methodLabel(m), hint: t(`rec.how_${m}`) }))}
                            />
                        )}

                        {key === 'details' && (
                            <div className="space-y-6">
                                <Field id="bulk_amount" label="Amount received" hint={`Up to ${money(totalOwed)} ETB (everything currently owed).`} error={over ? `This is more than everything owed (${money(totalOwed)} ETB).` : errors.amount}>
                                    <Input inputMode="decimal" value={data.amount} onChange={(e) => set('amount')(e.target.value)} autoFocus autoComplete="off" />
                                </Field>
                                <Button variant="quiet" size="md" onClick={() => set('amount')(String(totalOwed))}>Pay everything owed ({money(totalOwed)} ETB)</Button>

                                <div>
                                    <Field id="bulk_payer" label="Who paid? (optional)" hint="The name of the person who handed over the money, if you know it. You can leave this blank." error={errors.paid_by_name}>
                                        <Input value={data.paid_by_name} onChange={(e) => set('paid_by_name')(e.target.value)} autoComplete="off" maxLength={120} />
                                    </Field>
                                    {guardians.length > 0 && (
                                        <div className="mt-2 flex flex-wrap gap-2">
                                            {guardians.map((g) => <Button key={g.name} variant="secondary" size="md" onClick={() => set('paid_by_name')(g.name)}>{g.name} ({g.relationship})</Button>)}
                                        </div>
                                    )}
                                </div>

                                {!cash && (
                                    <Field id="bulk_reference" label="Reference number (optional)" hint="From the bank or mobile-money slip, if you have it. You can leave this blank." error={errors.reference_number}>
                                        <Input value={data.reference_number} onChange={(e) => { clearErrors('duplicate_reference'); setData((d) => ({ ...d, confirm_duplicate: false })); set('reference_number')(e.target.value); }} autoComplete="off" maxLength={255} />
                                    </Field>
                                )}

                                <Field id="bulk_note" label="Note" hint="What kind of payment this was." optional={data.method !== 'other'} error={errors.note}>
                                    <TextArea rows={2} maxLength={500} value={data.note} onChange={(e) => set('note')(e.target.value)} />
                                </Field>

                                <div>
                                    <p className="text-base font-medium text-stone-900 dark:text-stone-100">Receipt or slip <span className="font-normal text-stone-600 dark:text-stone-400">(Optional)</span></p>
                                    <p className="mt-0.5 text-sm text-stone-600 dark:text-stone-400">A photo of the bank/mobile-money slip, if there is one.</p>
                                    {preview && <img src={preview} alt="Receipt" className="mt-2 max-h-40 rounded-lg border-2 border-stone-200" />}
                                    <label className="mt-2 inline-flex min-h-[44px] cursor-pointer items-center rounded-xl border-2 border-stone-400 bg-white px-4 py-2 text-sm font-semibold text-stone-900 hover:bg-stone-50 dark:border-stone-500 dark:bg-stone-800 dark:text-stone-100">
                                        {data.proof ? 'Change file' : 'Choose a file'}
                                        <input type="file" accept=".jpg,.jpeg,.png,.pdf" className="sr-only" onChange={(e) => chooseFile(e.target.files[0])} />
                                    </label>
                                    {data.proof && <span className="ml-3 break-words text-sm text-stone-800 dark:text-stone-200">{data.proof.name}</span>}
                                    <FieldError message={fileError ?? errors.proof} />
                                </div>
                            </div>
                        )}

                        {key === 'review' && (
                            <div className="space-y-5">
                                <div className="space-y-2 rounded-2xl border-2 border-brand-300 bg-brand-50 p-5 dark:border-brand-700 dark:bg-brand-900/20">
                                    <p className="text-xl text-stone-900 dark:text-stone-100">Student: <strong>{student.name}</strong></p>
                                    <p className="text-xl text-stone-900 dark:text-stone-100">Amount: <strong className="text-3xl">{money(amountNum)} ETB</strong></p>
                                    <p className="text-xl text-stone-900 dark:text-stone-100">Still owed after: <strong className="text-3xl">{money(leftOverall)} ETB</strong></p>
                                </div>

                                <div>
                                    <p className="mb-2 text-lg font-semibold text-stone-900 dark:text-stone-100">This will be applied to:</p>
                                    <ul className="divide-y divide-stone-200 rounded-xl border-2 border-stone-200 dark:divide-stone-700 dark:border-stone-700">
                                        {allocation.map((a) => (
                                            <li key={a.id} className="flex items-center justify-between gap-3 p-3">
                                                <span className="text-stone-900 dark:text-stone-100">{a.period} ({a.number})</span>
                                                <span className="text-right">
                                                    <span className="block font-bold text-stone-900 dark:text-stone-100">{money(a.applied)} ETB</span>
                                                    <span className="block text-xs text-stone-500 dark:text-stone-400">{a.leftAfter === 0 ? 'Paid in full' : `${money(a.leftAfter)} ETB left`}</span>
                                                </span>
                                            </li>
                                        ))}
                                    </ul>
                                </div>

                                <ReviewList
                                    onEdit={wizard.goTo}
                                    rows={[
                                        { label: 'How', value: methodLabel(data.method), step: stepOf('how') },
                                        ...(data.paid_by_name.trim() ? [{ label: 'Who paid', value: data.paid_by_name.trim(), step: stepOf('details') }] : []),
                                        ...(data.reference_number.trim() ? [{ label: 'Reference', value: data.reference_number.trim(), step: stepOf('details') }] : []),
                                        ...(data.note.trim() ? [{ label: 'Note', value: data.note.trim(), step: stepOf('details') }] : []),
                                        { label: 'Receipt / slip', value: data.proof?.name ?? 'None', step: stepOf('details') },
                                    ]}
                                />
                                {duplicate && (
                                    <div className="space-y-3">
                                        <Alert tone="warning" title="This reference number was used before">{duplicate}</Alert>
                                        <CheckRow checked={data.confirm_duplicate} onChange={(v) => setData('confirm_duplicate', v)} label="Yes, this is really a different payment" id="confirm_duplicate" />
                                    </div>
                                )}
                                <p className="text-base text-stone-700 dark:text-stone-300">Each bill this covers gets its own receipt, ready to print on the next screen.</p>
                                {errors.amount && <Alert tone="danger" title="This could not be recorded">{errors.amount}</Alert>}
                            </div>
                        )}
                    </WizardShell>
                </Card>
            </Page>
        </AuthenticatedLayout>
    );
}
