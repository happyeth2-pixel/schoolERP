import { Alert, ChoiceGroup, Field, FieldError, Input, ReviewList, SuccessPanel, WizardShell, useWizard, Button } from '@/Components/ui';
import { BanknotesIcon, BuildingLibraryIcon, DevicePhoneMobileIcon, DocumentIcon, PhotoIcon, QuestionMarkCircleIcon } from '@heroicons/react/24/outline';
import { useForm } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';

// A parent telling the school "I paid this much, here is the receipt". Four short steps -- how, how
// much, proof, check -- built on the shared wizard (Components/ui/Wizard.jsx). It sends exactly the same
// four fields to the same endpoint (PaymentController@store), so every rule that matters (only your own
// child's invoice, amount <= balance, SafeUploadRule on the file, the school still verifies before
// anything counts) is enforced on the server as before. The checks here only say so a step earlier.

const METHODS = [
    ['bank_transfer', BuildingLibraryIcon],
    ['mobile_money', DevicePhoneMobileIcon],
    ['cash', BanknotesIcon],
    ['other', QuestionMarkCircleIcon],
];

const MAX_BYTES = 5 * 1024 * 1024; // mirrors 'max:5120' (KB) in PaymentController@store
const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'pdf'];

function money(n) {
    return Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

const round2 = (n) => Math.round(Number(n) * 100) / 100;

export default function PaymentProofWizard({ invoiceId, balance, hasAccounts = true }) {
    const { t } = useTranslation();
    const balanceNum = Math.max(0, round2(balance));

    const { data, setData, post, processing, errors, reset, clearErrors } = useForm({
        amount: '',
        method: '',
        reference_number: '',
        proof: null,
    });
    const [done, setDone] = useState(false);
    const [fileError, setFileError] = useState(null);

    const amountProblem = () => {
        const n = round2(data.amount);
        if (data.amount === '' || !(n > 0)) return 'payments.amount_error_required';
        if (n > balanceNum) return 'payments.amount_error_too_much';
        return null;
    };

    const wizard = useWizard({
        errors,
        onSubmit: () =>
            post(route('payments.store', invoiceId), {
                forceFormData: true,
                preserveScroll: true,
                onSuccess: () => {
                    reset();
                    setFileError(null);
                    wizard.reset();
                    setDone(true);
                },
            }),
        steps: [
            { key: 'method', label: t('payments.step_method'), fields: ['method'], isValid: () => data.method !== '', whyBlocked: t('payments.blocked_method') },
            { key: 'amount', label: t('payments.step_amount'), fields: ['amount'], isValid: () => amountProblem() === null, whyBlocked: t('payments.blocked_amount') },
            { key: 'proof', label: t('payments.step_proof'), fields: ['proof', 'reference_number'], isValid: () => data.proof !== null && fileError === null, whyBlocked: t('payments.blocked_proof') },
            { key: 'review', label: t('payments.step_review') },
        ],
    });

    // A file the server refused must not stay attached with Next lit up: make them pick another.
    useEffect(() => {
        if (errors.proof) setData('proof', null);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [errors]);

    const chooseFile = (file) => {
        clearErrors('proof');
        if (!file) return;
        const ext = file.name.split('.').pop().toLowerCase();
        if (!ALLOWED_EXTENSIONS.includes(ext)) {
            setData('proof', null);
            setFileError('payments.proof_error_type');
        } else if (file.size > MAX_BYTES) {
            setData('proof', null);
            setFileError('payments.proof_error_size');
        } else {
            setData('proof', file);
            setFileError(null);
        }
    };

    const previewUrl = useMemo(
        () => (data.proof && data.proof.type.startsWith('image/') ? URL.createObjectURL(data.proof) : null),
        [data.proof]
    );
    useEffect(() => () => { if (previewUrl) URL.revokeObjectURL(previewUrl); }, [previewUrl]);

    if (done) {
        return (
            <SuccessPanel
                title={t('payments.done_title')}
                actions={<Button variant="secondary" onClick={() => setDone(false)}>{t('payments.done_another')}</Button>}
            >
                {t('payments.done_body')}
            </SuccessPanel>
        );
    }

    const amountShown = data.amount !== '' ? amountProblem() : null;
    const stepKey = wizard.step.key;

    return (
        <div className="space-y-5 rounded-xl border border-gray-200 p-5 dark:border-gray-700">
            <div>
                <h3 className="text-base font-semibold text-gray-800 dark:text-gray-200">{t('payments.wizard_title')}</h3>
                <p className="mt-1 text-sm text-gray-600 dark:text-gray-400">{t(hasAccounts ? 'payments.wizard_intro' : 'payments.wizard_intro_no_accounts')}</p>
            </div>

            <WizardShell wizard={wizard} finalLabel={t('payments.send')} processing={processing}>
                {stepKey === 'method' && (
                    <div>
                        <ChoiceGroup
                            label={t('payments.step_method')}
                            value={data.method}
                            onChange={(v) => { clearErrors('method'); setData('method', v); }}
                            options={METHODS.map(([value, icon]) => ({ value, icon, label: t(`payments.method_${value}`), hint: t(`payments.method_${value}_hint`) }))}
                        />
                        <FieldError message={errors.method} />
                    </div>
                )}

                {stepKey === 'amount' && (
                    <div className="space-y-4">
                        <p className="text-base text-gray-800 dark:text-gray-200">{t('payments.amount_balance', { amount: money(balanceNum) })}</p>
                        <Field id="amount" label={t('payments.amount_label')} error={errors.amount ?? (amountShown ? t(amountShown, { amount: money(balanceNum) }) : undefined)}>
                            <Input
                                type="number"
                                inputMode="decimal"
                                step="0.01"
                                min="0.01"
                                max={balanceNum}
                                autoFocus
                                value={data.amount}
                                onChange={(e) => { clearErrors('amount'); setData('amount', e.target.value); }}
                                className="text-2xl"
                            />
                        </Field>
                        <Button variant="secondary" fullWidth onClick={() => { clearErrors('amount'); setData('amount', String(balanceNum)); }}>
                            {t('payments.amount_pay_full', { amount: money(balanceNum) })}
                        </Button>
                    </div>
                )}

                {stepKey === 'proof' && (
                    <div className="space-y-5">
                        <div>
                            <p className="text-base font-medium text-gray-900 dark:text-gray-100">{t('payments.proof_photo_label')}</p>
                            <p className="mt-0.5 text-sm text-gray-600 dark:text-gray-400">{t('payments.proof_photo_hint')}</p>

                            {data.proof && (
                                <div className="mt-3 flex items-center gap-3 rounded-xl border border-gray-200 p-3 dark:border-gray-600">
                                    {previewUrl ? (
                                        <img src={previewUrl} alt="" className="h-20 w-20 shrink-0 rounded-lg object-cover" />
                                    ) : (
                                        <span className="flex h-20 w-20 shrink-0 items-center justify-center rounded-lg bg-gray-100 dark:bg-gray-700">
                                            <DocumentIcon className="h-8 w-8 text-gray-500" />
                                        </span>
                                    )}
                                    <span className="min-w-0 break-words text-sm text-gray-800 dark:text-gray-200">{data.proof.name}</span>
                                </div>
                            )}

                            <label className="mt-3 flex min-h-[56px] cursor-pointer items-center justify-center gap-2 rounded-xl border-2 border-dashed border-indigo-500 bg-indigo-50 px-4 text-base font-semibold text-indigo-800 focus-within:ring-2 focus-within:ring-indigo-600 hover:bg-indigo-100 dark:bg-indigo-900/20 dark:text-indigo-200">
                                <PhotoIcon className="h-6 w-6" aria-hidden="true" />
                                {data.proof ? t('payments.proof_change') : t('payments.proof_choose')}
                                <input type="file" accept=".jpg,.jpeg,.png,.pdf" className="sr-only" onChange={(e) => chooseFile(e.target.files[0])} />
                            </label>
                            <FieldError message={fileError ? t(fileError) : errors.proof} />
                        </div>

                        <Field id="reference_number" label={t('payments.proof_reference_label')} hint={t('payments.proof_reference_hint')} error={errors.reference_number}>
                            <Input value={data.reference_number} onChange={(e) => { clearErrors('reference_number'); setData('reference_number', e.target.value); }} />
                        </Field>
                    </div>
                )}

                {stepKey === 'review' && (
                    <div className="space-y-4">
                        <p className="text-base text-gray-800 dark:text-gray-200">{t('payments.review_intro')}</p>
                        <ReviewList
                            onEdit={wizard.goTo}
                            rows={[
                                { label: t('payments.review_method'), value: t(`payments.method_${data.method}`), step: 0 },
                                { label: t('payments.review_amount'), value: `${money(data.amount)} ETB`, step: 1 },
                                { label: t('payments.review_reference'), value: data.reference_number.trim() || t('payments.review_no_reference'), step: 2 },
                                { label: t('payments.review_proof'), value: data.proof?.name, step: 2 },
                            ]}
                        />
                        <Alert tone="info">{t('payments.review_next')}</Alert>
                    </div>
                )}
            </WizardShell>
        </div>
    );
}
