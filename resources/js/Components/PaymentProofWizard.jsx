import { Alert, Button, ChoiceGroup, Field, FieldError, Input, ReviewList, SuccessPanel, WizardShell, useWizard } from '@/Components/ui';
import { BanknotesIcon, BuildingLibraryIcon, ClipboardDocumentIcon, DevicePhoneMobileIcon, DocumentIcon, PhotoIcon, QuestionMarkCircleIcon } from '@heroicons/react/24/outline';
import { useForm } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';

// A parent telling the school "I paid this much, here is the receipt". Four short steps -- how, how
// much, proof, check -- built on the shared wizard (Components/ui/Wizard.jsx). It sends exactly the same
// four fields to the same endpoint (PaymentController@store), so every rule that matters (only your own
// child's invoice, amount <= balance, SafeUploadRule on the file, the school still verifies before
// anything counts) is enforced on the server as before. The checks here only say so a step earlier.
//
// "How did you pay" and "which account did you send it to" used to be two separate things a parent had
// to hold in their head at once -- a plain list of the school's accounts shown once, far above, then an
// unrelated-looking "Bank transfer / Mobile money / Cash / Other" choice lower down that never referred
// back to it. They're now one step: picking "Bank transfer" shows the bank account to send to RIGHT
// THERE, picking "Mobile money" shows the mobile money number RIGHT THERE -- never two different things
// to compare across the page.

// A color per method, not just an icon, so the right card can be spotted at a glance -- for someone who
// does not read easily, "the blue one" or "the green one" is often faster to find than the label text.
const METHODS = [
    ['bank_transfer', BuildingLibraryIcon, 'bg-sky-100 text-sky-700 dark:bg-sky-900/40 dark:text-sky-300'],
    ['mobile_money', DevicePhoneMobileIcon, 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300'],
    ['cash', BanknotesIcon, 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300'],
    ['other', QuestionMarkCircleIcon, 'bg-violet-100 text-violet-700 dark:bg-violet-900/40 dark:text-violet-300'],
];
const ACCOUNT_TYPE_FOR_METHOD = { bank_transfer: 'bank', mobile_money: 'mobile_money' };

const MAX_BYTES = 5 * 1024 * 1024; // mirrors 'max:5120' (KB) in PaymentController@store
const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'pdf'];

function money(n) {
    return Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

const round2 = (n) => Math.round(Number(n) * 100) / 100;

/** One account to send money to -- the account NUMBER is the one thing a parent actually needs, so it is
 *  the biggest thing here; everything else (which bank, whose name it's under) is a smaller supporting
 *  line, not a second fact to compare against the number. */
function AccountToPay({ account }) {
    const { t } = useTranslation();
    const [copied, setCopied] = useState(false);
    const copy = async () => {
        try { await navigator.clipboard.writeText(String(account.account_number)); setCopied(true); setTimeout(() => setCopied(false), 2500); } catch { setCopied(false); }
    };

    return (
        <div className="flex flex-wrap items-center gap-3 rounded-xl border-2 border-brand-300 bg-brand-50 p-4 dark:border-brand-700 dark:bg-brand-900/20">
            {account.logo_url ? <img src={account.logo_url} alt="" className="h-11 w-11 shrink-0 rounded-lg bg-white object-contain p-1" /> : <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-brand-200 text-lg font-bold text-brand-900" aria-hidden="true">{account.display_name?.[0] ?? '?'}</span>}
            <div className="min-w-0 flex-1">
                <p className="text-sm text-stone-700 dark:text-stone-300">{account.display_name}{account.account_name ? ` — ${account.account_name}` : ''}</p>
                <p className="text-xl font-bold tracking-wide text-brand-900 dark:text-brand-200" data-testid="account-number">{account.account_number}</p>
            </div>
            <Button type="button" variant="secondary" size="md" icon={ClipboardDocumentIcon} onClick={copy}>{copied ? t('parent.pay_copied') : t('parent.pay_copy')}</Button>
        </div>
    );
}

export default function PaymentProofWizard({ invoiceId, balance, accounts = [] }) {
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
    // Typing a number is a real barrier for someone who has never used a keyboard -- "I paid the full
    // amount" (by far the common case) is a single tap that skips straight past it. The number field only
    // shows at all for the rare partial payment, behind its own button, never as the default.
    const [customAmount, setCustomAmount] = useState(() => data.amount !== '' && round2(data.amount) !== balanceNum);
    const [autoAdvance, setAutoAdvance] = useState(false);

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

    // setData() does not apply until the next render, so "move to the next step" has to wait for that
    // render (otherwise the step's own validity check still sees the old, empty amount and refuses).
    useEffect(() => {
        if (autoAdvance && data.amount !== '') {
            setAutoAdvance(false);
            wizard.next();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [autoAdvance, data.amount]);

    const payFullAmount = () => {
        clearErrors('amount');
        setData('amount', String(balanceNum));
        setAutoAdvance(true);
    };

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
        <div className="space-y-5">
            <WizardShell wizard={wizard} finalLabel={t('payments.send')} processing={processing}>
                {stepKey === 'method' && (
                    <div className="space-y-4">
                        <ChoiceGroup
                            label={t('payments.step_method')}
                            value={data.method}
                            onChange={(v) => { clearErrors('method'); setData('method', v); }}
                            options={METHODS.map(([value, icon, tint]) => ({ value, icon, tint, label: t(`payments.method_${value}`), hint: t(`payments.method_${value}_hint`) }))}
                        />
                        <FieldError message={errors.method} />

                        {/* Picking "Bank transfer" or "Mobile money" shows exactly where to send it, right
                            here -- never a separate list to remember while filling in the rest. */}
                        {ACCOUNT_TYPE_FOR_METHOD[data.method] && (
                            <div className="space-y-3">
                                {accounts.filter((a) => a.type === ACCOUNT_TYPE_FOR_METHOD[data.method]).length === 0 ? (
                                    <Alert tone="info">{t('payments.no_matching_account')}</Alert>
                                ) : (
                                    accounts.filter((a) => a.type === ACCOUNT_TYPE_FOR_METHOD[data.method]).map((a) => <AccountToPay key={a.id} account={a} />)
                                )}
                            </div>
                        )}
                        {data.method === 'cash' && <div data-testid="pay-at-school"><Alert tone="info">{t('parent.pay_at_school')}</Alert></div>}
                    </div>
                )}

                {stepKey === 'amount' && (
                    <div className="space-y-4">
                        {!customAmount ? (
                            <div className="space-y-3">
                                <Button variant="success" fullWidth className="!min-h-[72px] text-xl" onClick={payFullAmount}>
                                    {t('payments.amount_pay_full', { amount: money(balanceNum) })}
                                </Button>
                                <Button variant="secondary" fullWidth onClick={() => { clearErrors('amount'); setData('amount', ''); setCustomAmount(true); }}>
                                    {t('payments.amount_pay_other')}
                                </Button>
                            </div>
                        ) : (
                            <>
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
                                <Button variant="quiet" fullWidth onClick={() => { clearErrors('amount'); setData('amount', ''); setCustomAmount(false); }}>
                                    {t('payments.amount_back_to_full')}
                                </Button>
                            </>
                        )}
                    </div>
                )}

                {stepKey === 'proof' && (
                    <div className="space-y-5">
                        <div>
                            <p className="text-base font-medium text-stone-900 dark:text-stone-100">{t('payments.proof_photo_label')}</p>

                            {data.proof && (
                                <div className="mt-3 flex items-center gap-3 rounded-xl border border-stone-200 p-3 dark:border-stone-600">
                                    {previewUrl ? (
                                        <img src={previewUrl} alt="" className="h-20 w-20 shrink-0 rounded-lg object-cover" />
                                    ) : (
                                        <span className="flex h-20 w-20 shrink-0 items-center justify-center rounded-lg bg-stone-100 dark:bg-stone-700">
                                            <DocumentIcon className="h-8 w-8 text-stone-500" />
                                        </span>
                                    )}
                                    <span className="min-w-0 break-words text-sm text-stone-800 dark:text-stone-200">{data.proof.name}</span>
                                </div>
                            )}

                            <label className="mt-3 flex min-h-[56px] cursor-pointer items-center justify-center gap-2 rounded-xl border-2 border-dashed border-brand-500 bg-brand-50 px-4 text-base font-semibold text-brand-800 focus-within:ring-2 focus-within:ring-brand-600 hover:bg-brand-100 dark:bg-brand-900/20 dark:text-brand-200">
                                <PhotoIcon className="h-6 w-6" aria-hidden="true" />
                                {data.proof ? t('payments.proof_change') : t('payments.proof_choose')}
                                <input type="file" accept=".jpg,.jpeg,.png,.pdf" className="sr-only" onChange={(e) => chooseFile(e.target.files[0])} />
                            </label>
                            <FieldError message={fileError ? t(fileError) : errors.proof} />
                        </div>

                        <Field id="reference_number" label={t('payments.proof_reference_label')} error={errors.reference_number}>
                            <Input value={data.reference_number} onChange={(e) => { clearErrors('reference_number'); setData('reference_number', e.target.value); }} />
                        </Field>
                    </div>
                )}

                {stepKey === 'review' && (
                    <div className="space-y-4">
                        <p className="text-base text-stone-800 dark:text-stone-200">{t('payments.review_intro')}</p>
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
