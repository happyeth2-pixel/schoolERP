import Modal from '@/Components/Modal';
import DateInput from '@/Components/DateInput';
import { Button, Field, Input, Select, TextArea } from '@/Components/ui';
import { useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

const today = () => new Date().toISOString().slice(0, 10);

// Recording what actually arrived against a sent purchase order -- only lines still expecting something show
// up here at all, and each is capped to what is still outstanding, so a delivery can never be over-recorded.

export default function GoodsReceiptDialog({ open, order, stores, onClose }) {
    const { t } = useTranslation();
    const receivableItems = order.items.filter((i) => i.remaining_to_receive > 0);
    const { data, setData, post, transform, processing, errors, reset } = useForm({
        store_id: stores[0]?.id ? String(stores[0].id) : '',
        received_date: today(),
        notes: '',
        items: Object.fromEntries(receivableItems.map((i) => [i.id, { quantity_received: String(i.remaining_to_receive) }])),
    });

    const setLine = (id, patch) => setData('items', { ...data.items, [id]: { ...data.items[id], ...patch } });

    const submit = (e) => {
        e.preventDefault();
        transform((d) => ({
            ...d,
            items: Object.entries(d.items).map(([purchase_order_item_id, line]) => ({ purchase_order_item_id, ...line })),
        }));
        post(route('goods-receipts.store', order.id), { preserveScroll: true, onSuccess: () => { reset(); onClose(); } });
    };

    return (
        <Modal show={open} onClose={onClose} maxWidth="lg">
            {open && (
                <form onSubmit={submit} className="space-y-6 p-6" data-testid="goods-receipt-form">
                    <h2 className="text-2xl font-bold text-stone-900 dark:text-stone-100">{t('goods_receipt.add_title')}</h2>
                    <Field id="gr_store" label={t('goods_receipt.store')} error={errors.store_id}>
                        <Select value={data.store_id} onChange={(e) => setData('store_id', e.target.value)}>
                            {stores.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                        </Select>
                    </Field>
                    <Field id="gr_date" label={t('goods_receipt.received_date')} error={errors.received_date}>
                        <DateInput value={data.received_date} onChange={(v) => setData('received_date', v)} />
                    </Field>

                    <div className="space-y-4 rounded-xl border-2 border-stone-200 p-4 dark:border-stone-700">
                        <p className="text-base font-semibold text-stone-900 dark:text-stone-100">{t('goods_receipt.lines_title')}</p>
                        {receivableItems.map((i, index) => (
                            <div key={i.id} className="space-y-2 border-b border-stone-200 pb-3 last:border-0 last:pb-0 dark:border-stone-700">
                                <p className="text-sm font-medium text-stone-800 dark:text-stone-200">{i.description}</p>
                                <p className="text-xs text-stone-500 dark:text-stone-400">{t('goods_receipt.still_expected', { count: i.remaining_to_receive })}</p>
                                <Field id={`gr_qty_${i.id}`} label={t('quotation.quantity')} error={errors[`items.${index}.quantity_received`]}>
                                    <Input inputMode="numeric" value={data.items[i.id].quantity_received} onChange={(e) => setLine(i.id, { quantity_received: e.target.value })} autoComplete="off" />
                                </Field>
                            </div>
                        ))}
                    </div>

                    <Field id="gr_notes" label={t('quotation.notes')} optional error={errors.notes}>
                        <TextArea id="gr_notes" value={data.notes} onChange={(e) => setData('notes', e.target.value)} />
                    </Field>

                    <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        <Button type="button" variant="secondary" onClick={onClose}>{t('common.cancel')}</Button>
                        <Button type="submit" busy={processing} disabled={!data.store_id || receivableItems.length === 0}>{t('goods_receipt.save_button')}</Button>
                    </div>
                </form>
            )}
        </Modal>
    );
}
