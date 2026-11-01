import Modal from '@/Components/Modal';
import { Button, Field, Input, Select, TextArea } from '@/Components/ui';
import { useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

export default function SupplierPaymentReversalDialog({ open, payment, kinds, onClose }) {
    const { t } = useTranslation();
    const { data, setData, post, processing, errors, reset } = useForm({
        amount: payment ? String(payment.reversible) : '',
        kind: 'reversal',
        reason: '',
        reference: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('supplier-payments.reverse', payment.id), { preserveScroll: true, onSuccess: () => { reset(); onClose(); } });
    };

    return (
        <Modal show={open} onClose={onClose} maxWidth="lg">
            {open && payment && (
                <form onSubmit={submit} className="space-y-6 p-6" data-testid="supplier-payment-reversal-form">
                    <h2 className="text-2xl font-bold text-stone-900 dark:text-stone-100">{t('supplier_payment.reverse_title')}</h2>
                    <p className="text-sm text-stone-600 dark:text-stone-400">{t('supplier_payment.reverse_desc', { amount: payment.reversible })}</p>
                    <Field id="spr_kind" label={t('supplier_payment.kind')} error={errors.kind}>
                        <Select value={data.kind} onChange={(e) => setData('kind', e.target.value)}>
                            {Object.entries(kinds).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                        </Select>
                    </Field>
                    <Field id="spr_amount" label={t('supplier_payment.amount')} error={errors.amount}>
                        <Input inputMode="decimal" value={data.amount} onChange={(e) => setData('amount', e.target.value)} autoComplete="off" />
                    </Field>
                    <Field id="spr_reason" label={t('supplier_payment.reverse_reason')} error={errors.reason}>
                        <TextArea id="spr_reason" value={data.reason} onChange={(e) => setData('reason', e.target.value)} />
                    </Field>
                    <Field id="spr_reference" label={t('supplier_payment.reference')} optional error={errors.reference}>
                        <Input value={data.reference} onChange={(e) => setData('reference', e.target.value)} maxLength={255} autoComplete="off" />
                    </Field>
                    <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        <Button type="button" variant="secondary" onClick={onClose}>{t('common.cancel')}</Button>
                        <Button type="submit" variant="danger" busy={processing} disabled={!data.amount || data.reason.trim().length < 3}>{t('supplier_payment.reverse_button')}</Button>
                    </div>
                </form>
            )}
        </Modal>
    );
}
