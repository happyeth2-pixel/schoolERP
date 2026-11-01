import Modal from '@/Components/Modal';
import DateInput from '@/Components/DateInput';
import { Button, Field, Input, Select, TextArea } from '@/Components/ui';
import { useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

const money = (n) => Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const today = () => new Date().toISOString().slice(0, 10);

// Recording one supplier's quoted prices against an approved purchase request -- one row per line already
// asked for, so a later side-by-side comparison is always apples to apples across suppliers.

export default function QuotationDialog({ open, purchaseRequest, suppliers, onClose }) {
    const { t } = useTranslation();
    const { data, setData, post, transform, processing, errors, reset } = useForm({
        supplier_id: '', reference: '', quotation_date: today(), validity_date: '', delivery_info: '', notes: '',
        items: Object.fromEntries(purchaseRequest.items.map((i) => [i.id, { quantity: String(i.quantity), unit_price: '', tax_amount: '' }])),
    });

    const setLine = (id, patch) => setData('items', { ...data.items, [id]: { ...data.items[id], ...patch } });
    const lineTotal = (id) => {
        const l = data.items[id];
        return l.unit_price !== '' ? Number(l.quantity || 0) * Number(l.unit_price) + Number(l.tax_amount || 0) : null;
    };
    const grandTotal = purchaseRequest.items.reduce((sum, i) => sum + (lineTotal(i.id) ?? 0), 0);
    const allPriced = purchaseRequest.items.every((i) => data.items[i.id].unit_price !== '');

    const submit = (e) => {
        e.preventDefault();
        transform((d) => ({
            ...d,
            items: Object.entries(d.items).map(([purchase_request_item_id, line]) => ({ purchase_request_item_id, ...line })),
        }));
        post(route('quotations.store', purchaseRequest.id), { preserveScroll: true, onSuccess: () => { reset(); onClose(); } });
    };

    return (
        <Modal show={open} onClose={onClose} maxWidth="lg">
            {open && (
                <form onSubmit={submit} className="space-y-6 p-6" data-testid="quotation-form">
                    <h2 className="text-2xl font-bold text-stone-900 dark:text-stone-100">{t('quotation.add_title')}</h2>
                    <Field id="quote_supplier" label={t('quotation.supplier')} error={errors.supplier_id}>
                        <Select value={data.supplier_id} onChange={(e) => setData('supplier_id', e.target.value)}>
                            <option value="">--</option>
                            {suppliers.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                        </Select>
                    </Field>
                    <Field id="quote_reference" label={t('quotation.reference')} optional error={errors.reference}>
                        <Input value={data.reference} onChange={(e) => setData('reference', e.target.value)} maxLength={255} autoComplete="off" />
                    </Field>
                    <Field id="quote_date" label={t('quotation.quotation_date')} error={errors.quotation_date}>
                        <DateInput value={data.quotation_date} onChange={(v) => setData('quotation_date', v)} />
                    </Field>
                    <Field id="quote_validity" label={t('quotation.validity_date')} optional error={errors.validity_date}>
                        <DateInput value={data.validity_date} onChange={(v) => setData('validity_date', v)} />
                    </Field>
                    <Field id="quote_delivery" label={t('quotation.delivery_info')} optional error={errors.delivery_info}>
                        <Input value={data.delivery_info} onChange={(e) => setData('delivery_info', e.target.value)} maxLength={255} autoComplete="off" />
                    </Field>

                    <div className="space-y-4 rounded-xl border-2 border-stone-200 p-4 dark:border-stone-700">
                        <p className="text-base font-semibold text-stone-900 dark:text-stone-100">{t('quotation.prices_title')}</p>
                        {purchaseRequest.items.map((i, index) => (
                            <div key={i.id} className="space-y-2 border-b border-stone-200 pb-3 last:border-0 last:pb-0 dark:border-stone-700">
                                <p className="text-sm font-medium text-stone-800 dark:text-stone-200">{i.description}</p>
                                <div className="grid grid-cols-3 gap-2">
                                    <Field id={`quote_qty_${i.id}`} label={t('quotation.quantity')} error={errors[`items.${index}.quantity`]}>
                                        <Input inputMode="numeric" value={data.items[i.id].quantity} onChange={(e) => setLine(i.id, { quantity: e.target.value })} autoComplete="off" />
                                    </Field>
                                    <Field id={`quote_price_${i.id}`} label={t('quotation.unit_price')} error={errors[`items.${index}.unit_price`]}>
                                        <Input inputMode="decimal" value={data.items[i.id].unit_price} onChange={(e) => setLine(i.id, { unit_price: e.target.value })} autoComplete="off" />
                                    </Field>
                                    <Field id={`quote_tax_${i.id}`} label={t('quotation.tax_amount')} optional error={errors[`items.${index}.tax_amount`]}>
                                        <Input inputMode="decimal" value={data.items[i.id].tax_amount} onChange={(e) => setLine(i.id, { tax_amount: e.target.value })} autoComplete="off" />
                                    </Field>
                                </div>
                                {lineTotal(i.id) !== null && <p className="text-sm text-stone-700 dark:text-stone-300">{t('quotation.line_total', { amount: money(lineTotal(i.id)) })}</p>}
                            </div>
                        ))}
                        {allPriced && <p className="text-lg font-bold text-stone-900 dark:text-stone-100">{t('quotation.grand_total', { amount: money(grandTotal) })}</p>}
                    </div>

                    <Field id="quote_notes" label={t('quotation.notes')} optional error={errors.notes}>
                        <TextArea id="quote_notes" value={data.notes} onChange={(e) => setData('notes', e.target.value)} />
                    </Field>

                    <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        <Button type="button" variant="secondary" onClick={onClose}>{t('common.cancel')}</Button>
                        <Button type="submit" busy={processing} disabled={!data.supplier_id || !allPriced}>{t('quotation.save_button')}</Button>
                    </div>
                </form>
            )}
        </Modal>
    );
}
