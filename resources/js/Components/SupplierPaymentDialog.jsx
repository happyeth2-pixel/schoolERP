import Modal from '@/Components/Modal';
import DateInput from '@/Components/DateInput';
import { Button, Field, Input, Select, TextArea } from '@/Components/ui';
import { useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

const today = () => new Date().toISOString().slice(0, 10);

export default function SupplierPaymentDialog({ open, bill, onClose }) {
    const { t } = useTranslation();
    const { data, setData, post, processing, errors, reset } = useForm({
        amount: bill.balance ? String(bill.balance) : '',
        method: 'bank_transfer',
        reference_number: '',
        paid_date: today(),
        notes: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('supplier-payments.store', bill.id), { preserveScroll: true, onSuccess: () => { reset(); onClose(); } });
    };

    return (
        <Modal show={open} onClose={onClose} maxWidth="lg">
            {open && (
                <form onSubmit={submit} className="space-y-6 p-6" data-testid="supplier-payment-form">
                    <h2 className="text-2xl font-bold text-stone-900 dark:text-stone-100">{t('supplier_payment.add_title')}</h2>
                    <Field id="sp_amount" label={t('supplier_payment.amount')} error={errors.amount}>
                        <Input inputMode="decimal" value={data.amount} onChange={(e) => setData('amount', e.target.value)} autoComplete="off" />
                    </Field>
                    <Field id="sp_method" label={t('supplier_payment.method')} error={errors.method}>
                        <Select value={data.method} onChange={(e) => setData('method', e.target.value)}>
                            <option value="bank_transfer">{t('supplier_payment.method_bank_transfer')}</option>
                            <option value="mobile_money">{t('supplier_payment.method_mobile_money')}</option>
                            <option value="cash">{t('supplier_payment.method_cash')}</option>
                            <option value="other">{t('supplier_payment.method_other')}</option>
                        </Select>
                    </Field>
                    <Field id="sp_reference" label={t('supplier_payment.reference')} optional error={errors.reference_number}>
                        <Input value={data.reference_number} onChange={(e) => setData('reference_number', e.target.value)} maxLength={255} autoComplete="off" />
                    </Field>
                    <Field id="sp_date" label={t('supplier_payment.paid_date')} error={errors.paid_date}>
                        <DateInput value={data.paid_date} onChange={(v) => setData('paid_date', v)} />
                    </Field>
                    <Field id="sp_notes" label={t('quotation.notes')} optional error={errors.notes}>
                        <TextArea id="sp_notes" value={data.notes} onChange={(e) => setData('notes', e.target.value)} />
                    </Field>
                    <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        <Button type="button" variant="secondary" onClick={onClose}>{t('common.cancel')}</Button>
                        <Button type="submit" busy={processing} disabled={!data.amount}>{t('supplier_payment.save_button')}</Button>
                    </div>
                </form>
            )}
        </Modal>
    );
}
