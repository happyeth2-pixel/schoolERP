import Modal from '@/Components/Modal';
import { Alert, Button, Field, Input } from '@/Components/ui';
import { useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

// Giving a discount and waiving what is left on a bill: each one focused question, the effect shown BEFORE saving
// (what the total becomes), the school's limit stated in words, and a button that names the amount. The limits are
// only explained here; the server still enforces them (an ordinary staff member cannot go past the school's limit,
// nothing can push a total below what was already collected, and a reason is required) and still audits the change.

const money = (n) => Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const num = (v) => { const n = Number(String(v).replace(',', '.')); return Number.isFinite(n) ? n : NaN; };

export function DiscountDialog({ open, invoice, limits, canApprove, onClose }) {
    return (
        <Modal show={open} onClose={onClose} maxWidth="lg">
            {open && <DiscountForm invoice={invoice} limits={limits} canApprove={canApprove} onDone={onClose} />}
        </Modal>
    );
}

function DiscountForm({ invoice, limits, canApprove, onDone }) {
    const { t } = useTranslation();
    const { data, setData, patch, processing, errors, clearErrors, transform } = useForm({ discount_amount: String(invoice.discount_amount ?? 0), discount_reason: invoice.discount_reason ?? '' });
    const gross = Number(invoice.subtotal);
    const amount = num(data.discount_amount);
    const valid = Number.isFinite(amount) && amount >= 0;
    const limit = Math.round(gross * Number(limits.discount_percent)) / 100;
    const above = valid && amount > limit && !canApprove;
    const collected = Number(invoice.paid) - Number(invoice.refunded ?? 0);
    const totalAfter = valid ? gross - amount : null;
    const belowCollected = valid && totalAfter < collected - 0.001;
    const removing = valid && amount === 0;
    const needsReason = valid && amount > 0;

    const submit = (e) => {
        e.preventDefault();
        transform((d) => ({ discount_amount: valid ? amount : d.discount_amount, discount_reason: needsReason ? d.discount_reason.trim() : '' }));
        patch(route('invoices.discount', invoice.id), { preserveScroll: true, onSuccess: onDone });
    };

    return (
        <form onSubmit={submit} className="space-y-6 p-6" data-testid="discount-form">
            <div>
                <h2 className="text-2xl font-bold text-gray-900 dark:text-gray-100">{t('adj.d_title', { number: invoice.invoice_number })}</h2>
                <p className="mt-1 text-base text-gray-600 dark:text-gray-400">{t('adj.d_intro', { student: invoice.student.name, subtotal: money(gross) })}</p>
            </div>
            <Field id="discount_amount" label={t('adj.d_amount')} hint={t('adj.d_amount_hint')} error={errors.discount_amount ?? (data.discount_amount !== '' && !valid ? t('adj.err_number') : undefined)}>
                <Input inputMode="decimal" value={data.discount_amount} onChange={(e) => { clearErrors('discount_amount'); setData('discount_amount', e.target.value); }} autoComplete="off" />
            </Field>
            {valid && (
                <p className="rounded-xl border-2 border-indigo-300 bg-indigo-50 p-4 text-lg font-semibold text-gray-900 dark:border-indigo-700 dark:bg-indigo-900/20 dark:text-gray-100" data-testid="d-preview">
                    {removing ? t('adj.d_preview_none', { total: money(gross) }) : t('adj.d_preview', { total: money(totalAfter), was: money(gross) })}
                </p>
            )}
            {needsReason && (
                <Field id="discount_reason" label={t('adj.d_reason')} hint={t('adj.d_reason_hint')} error={errors.discount_reason}>
                    <Input value={data.discount_reason} onChange={(e) => { clearErrors('discount_reason'); setData('discount_reason', e.target.value); }} maxLength={255} autoComplete="off" />
                </Field>
            )}
            <p className="text-base text-gray-700 dark:text-gray-300" data-testid="d-limit">
                {canApprove ? t('adj.limit_any') : t('adj.d_limit', { percent: Number(limits.discount_percent), amount: money(limit) })}
            </p>
            {above && <Alert tone="warning" title={t('adj.d_above_title')}>{t('adj.d_above_body', { amount: money(limit) })}</Alert>}
            {belowCollected && <Alert tone="warning" title={t('adj.d_below_title')}>{t('adj.d_below_body', { collected: money(collected) })}</Alert>}
            <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                <Button variant="secondary" onClick={onDone}>{t('common.cancel')}</Button>
                <Button type="submit" variant="success" busy={processing} disabled={!valid || (needsReason && data.discount_reason.trim() === '')}>
                    {removing ? t('adj.d_button_remove') : t('adj.d_button', { amount: valid ? money(amount) : '' })}
                </Button>
            </div>
        </form>
    );
}

export function WaiveDialog({ open, invoice, limits, canApprove, onClose }) {
    return (
        <Modal show={open} onClose={onClose} maxWidth="lg">
            {open && <WaiveForm invoice={invoice} limits={limits} canApprove={canApprove} onDone={onClose} />}
        </Modal>
    );
}

function WaiveForm({ invoice, limits, canApprove, onDone }) {
    const { t } = useTranslation();
    const { data, setData, post, processing, errors, clearErrors } = useForm({ reason: '' });
    const owed = Number(invoice.balance);
    const above = !canApprove && owed > Number(limits.waiver_amount);

    const submit = (e) => {
        e.preventDefault();
        post(route('invoices.toggle-waive', invoice.id), { preserveScroll: true, onSuccess: onDone });
    };

    return (
        <form onSubmit={submit} className="space-y-6 p-6" data-testid="waive-form">
            <div>
                <h2 className="text-2xl font-bold text-gray-900 dark:text-gray-100">{t('adj.w_title', { number: invoice.invoice_number })}</h2>
                <p className="mt-1 text-lg font-semibold text-gray-900 dark:text-gray-100">{t('adj.w_owed', { amount: money(owed), student: invoice.student.name })}</p>
            </div>
            <Alert tone="info" title={t('adj.w_effect_title')}>{t('adj.w_effect_body', { amount: money(owed) })}</Alert>
            <Field id="waive_reason" label={t('adj.w_reason')} hint={t('adj.w_reason_hint')} error={errors.reason}>
                <Input value={data.reason} onChange={(e) => { clearErrors('reason'); setData('reason', e.target.value); }} maxLength={255} autoComplete="off" />
            </Field>
            <p className="text-base text-gray-700 dark:text-gray-300" data-testid="w-limit">{canApprove ? t('adj.limit_any') : t('adj.w_limit', { amount: money(limits.waiver_amount) })}</p>
            {above && <Alert tone="warning" title={t('adj.w_above_title')}>{t('adj.w_above_body', { amount: money(limits.waiver_amount) })}</Alert>}
            <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                <Button variant="secondary" onClick={onDone}>{t('common.cancel')}</Button>
                <Button type="submit" variant="danger" busy={processing} disabled={data.reason.trim() === ''}>{t('adj.w_button', { amount: money(owed) })}</Button>
            </div>
        </form>
    );
}
