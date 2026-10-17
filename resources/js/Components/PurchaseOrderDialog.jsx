import Modal from '@/Components/Modal';
import { Button, Field, Input, Select, TextArea } from '@/Components/ui';
import { useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

const money = (n) => Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const today = () => new Date().toISOString().slice(0, 10);

// Ordering lines that still have something left to order -- a fully-ordered line (remaining_to_order === 0)
// is left out of the form entirely, so it can never be accidentally over-ordered.

function initialLines(items, selectedQuotation) {
    const priced = selectedQuotation ? Object.fromEntries(selectedQuotation.items.map((i) => [i.purchase_request_item_id, i])) : {};

    return Object.fromEntries(
        items
            .filter((i) => i.remaining_to_order > 0)
            .map((i) => {
                const fromQuote = priced[i.id];
                return [
                    i.id,
                    {
                        quantity: String(Math.min(i.remaining_to_order, fromQuote ? fromQuote.quantity : i.remaining_to_order)),
                        unit_price: fromQuote ? String(fromQuote.unit_price) : '',
                        tax_amount: fromQuote ? String(fromQuote.tax_amount) : '',
                    },
                ];
            })
    );
}

export default function PurchaseOrderDialog({ open, purchaseRequest, suppliers, selectedQuotation, onClose }) {
    const { t } = useTranslation();
    const orderableItems = purchaseRequest.items.filter((i) => i.remaining_to_order > 0);
    const { data, setData, post, transform, processing, errors, reset } = useForm({
        supplier_id: selectedQuotation ? String(selectedQuotation.supplier_id ?? '') : '',
        quotation_id: selectedQuotation?.id ?? '',
        order_date: today(),
        expected_delivery_date: '',
        delivery_info: selectedQuotation?.delivery_info ?? '',
        notes: '',
        items: initialLines(purchaseRequest.items, selectedQuotation),
    });

    const setLine = (id, patch) => setData('items', { ...data.items, [id]: { ...data.items[id], ...patch } });
    const lineTotal = (id) => {
        const l = data.items[id];
        return l.unit_price !== '' ? Number(l.quantity || 0) * Number(l.unit_price) + Number(l.tax_amount || 0) : null;
    };
    const grandTotal = orderableItems.reduce((sum, i) => sum + (lineTotal(i.id) ?? 0), 0);
    const allPriced = orderableItems.every((i) => data.items[i.id]?.unit_price !== '');

    const submit = (e) => {
        e.preventDefault();
        transform((d) => ({
            ...d,
            quotation_id: d.quotation_id || null,
            items: Object.entries(d.items).map(([purchase_request_item_id, line]) => ({ purchase_request_item_id, ...line })),
        }));
        post(route('purchase-orders.store', purchaseRequest.id), { preserveScroll: true, onSuccess: () => { reset(); onClose(); } });
    };

    return (
        <Modal show={open} onClose={onClose} maxWidth="lg">
            {open && (
                <form onSubmit={submit} className="space-y-6 p-6" data-testid="purchase-order-form">
                    <h2 className="text-2xl font-bold text-gray-900 dark:text-gray-100">{t('purchase_order.add_title')}</h2>
                    {selectedQuotation && (
                        <p className="text-sm text-gray-600 dark:text-gray-400">{t('purchase_order.prefilled_from_quotation', { supplier: selectedQuotation.supplier_name })}</p>
                    )}
                    <Field id="po_supplier" label={t('purchase_order.supplier')} error={errors.supplier_id}>
                        <Select value={data.supplier_id} onChange={(e) => setData('supplier_id', e.target.value)}>
                            <option value="">--</option>
                            {suppliers.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                        </Select>
                    </Field>
                    <Field id="po_order_date" label={t('purchase_order.order_date')} error={errors.order_date}>
                        <Input type="date" value={data.order_date} onChange={(e) => setData('order_date', e.target.value)} />
                    </Field>
                    <Field id="po_delivery_date" label={t('purchase_order.expected_delivery_date')} optional error={errors.expected_delivery_date}>
                        <Input type="date" value={data.expected_delivery_date} onChange={(e) => setData('expected_delivery_date', e.target.value)} />
                    </Field>
                    <Field id="po_delivery_info" label={t('purchase_order.delivery_info')} optional error={errors.delivery_info}>
                        <Input value={data.delivery_info} onChange={(e) => setData('delivery_info', e.target.value)} maxLength={255} autoComplete="off" />
                    </Field>

                    <div className="space-y-4 rounded-xl border-2 border-gray-200 p-4 dark:border-gray-700">
                        <p className="text-base font-semibold text-gray-900 dark:text-gray-100">{t('purchase_order.lines_title')}</p>
                        {orderableItems.map((i, index) => (
                            <div key={i.id} className="space-y-2 border-b border-gray-200 pb-3 last:border-0 last:pb-0 dark:border-gray-700">
                                <p className="text-sm font-medium text-gray-800 dark:text-gray-200">{i.description}</p>
                                <p className="text-xs text-gray-500 dark:text-gray-400">{t('purchase_order.remaining_to_order', { count: i.remaining_to_order })}</p>
                                <div className="grid grid-cols-3 gap-2">
                                    <Field id={`po_qty_${i.id}`} label={t('quotation.quantity')} error={errors[`items.${index}.quantity`]}>
                                        <Input inputMode="numeric" value={data.items[i.id].quantity} onChange={(e) => setLine(i.id, { quantity: e.target.value })} autoComplete="off" />
                                    </Field>
                                    <Field id={`po_price_${i.id}`} label={t('quotation.unit_price')} error={errors[`items.${index}.unit_price`]}>
                                        <Input inputMode="decimal" value={data.items[i.id].unit_price} onChange={(e) => setLine(i.id, { unit_price: e.target.value })} autoComplete="off" />
                                    </Field>
                                    <Field id={`po_tax_${i.id}`} label={t('quotation.tax_amount')} optional error={errors[`items.${index}.tax_amount`]}>
                                        <Input inputMode="decimal" value={data.items[i.id].tax_amount} onChange={(e) => setLine(i.id, { tax_amount: e.target.value })} autoComplete="off" />
                                    </Field>
                                </div>
                                {lineTotal(i.id) !== null && <p className="text-sm text-gray-700 dark:text-gray-300">{t('quotation.line_total', { amount: money(lineTotal(i.id)) })}</p>}
                            </div>
                        ))}
                        {allPriced && <p className="text-lg font-bold text-gray-900 dark:text-gray-100">{t('quotation.grand_total', { amount: money(grandTotal) })}</p>}
                    </div>

                    <Field id="po_notes" label={t('quotation.notes')} optional error={errors.notes}>
                        <TextArea id="po_notes" value={data.notes} onChange={(e) => setData('notes', e.target.value)} />
                    </Field>

                    <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        <Button type="button" variant="secondary" onClick={onClose}>{t('common.cancel')}</Button>
                        <Button type="submit" busy={processing} disabled={!data.supplier_id || !allPriced || orderableItems.length === 0}>{t('purchase_order.save_button')}</Button>
                    </div>
                </form>
            )}
        </Modal>
    );
}
