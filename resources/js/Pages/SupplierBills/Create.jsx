import DateInput from '@/Components/DateInput';
import { BackLink, Button, Field, Input, Page, Select, TextArea } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PlusIcon, TrashIcon } from '@heroicons/react/24/outline';
import { router, useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

const emptyLine = () => ({ purchase_order_item_id: '', expense_account_id: '', description: '', quantity: '1', unit_price: '', tax_amount: '' });
const money = (n) => Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const today = () => new Date().toISOString().slice(0, 10);

export default function Create({ suppliers, eligibleOrders, fromOrder, expenseAccounts }) {
    const { t } = useTranslation();
    const { data, setData, post, processing, errors } = useForm({
        supplier_id: fromOrder ? String(fromOrder.supplier_id) : '',
        purchase_order_id: fromOrder ? String(fromOrder.id) : '',
        supplier_reference: '',
        bill_date: today(),
        due_date: '',
        notes: '',
        items: fromOrder
            ? fromOrder.items.map((i) => ({ purchase_order_item_id: String(i.id), expense_account_id: '', description: i.description, quantity: String(i.remaining_to_bill), unit_price: String(i.unit_price), tax_amount: '' }))
            : [emptyLine()],
    });

    const pickOrder = (orderId) => {
        router.get(route('supplier-bills.create'), orderId ? { purchase_order_id: orderId } : {});
    };

    const setLine = (index, patch) => setData('items', data.items.map((l, i) => (i === index ? { ...l, ...patch } : l)));
    const addLine = () => setData('items', [...data.items, emptyLine()]);
    const removeLine = (index) => setData('items', data.items.filter((_, i) => i !== index));

    const lineTotal = (l) => (l.unit_price !== '' ? Number(l.quantity || 0) * Number(l.unit_price) + Number(l.tax_amount || 0) : null);
    const grandTotal = data.items.reduce((sum, l) => sum + (lineTotal(l) ?? 0), 0);
    const linesValid = data.items.length > 0 && data.items.every((l) => l.description.trim() !== '' && Number(l.quantity) > 0 && l.unit_price !== '');

    const submit = (e) => {
        e.preventDefault();
        post(route('supplier-bills.store'));
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">{t('supplier_bill.new_title')}</h2>}>
            <Page title={t('supplier_bill.new_title')} back={<BackLink href={route('supplier-bills.index')}>{t('supplier_bill.back_to_list')}</BackLink>}>
                <form onSubmit={submit} className="space-y-6">
                    {!fromOrder && (
                        <Field id="bill_order" label={t('supplier_bill.from_order')} optional hint={t('supplier_bill.from_order_hint')}>
                            <Select value="" onChange={(e) => pickOrder(e.target.value)}>
                                <option value="">{t('supplier_bill.no_order_selected')}</option>
                                {eligibleOrders.map((o) => <option key={o.id} value={o.id}>{o.number} &middot; {o.supplier.name}</option>)}
                            </Select>
                        </Field>
                    )}
                    {fromOrder && (
                        <p className="text-sm text-stone-600 dark:text-stone-400">{t('supplier_bill.billing_against_x', { number: fromOrder.number })}</p>
                    )}

                    <Field id="bill_supplier" label={t('supplier_bill.supplier')} error={errors.supplier_id}>
                        <Select value={data.supplier_id} onChange={(e) => setData('supplier_id', e.target.value)} disabled={!!fromOrder}>
                            <option value="">--</option>
                            {suppliers.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                        </Select>
                    </Field>
                    <Field id="bill_reference" label={t('supplier_bill.supplier_reference')} optional error={errors.supplier_reference}>
                        <Input value={data.supplier_reference} onChange={(e) => setData('supplier_reference', e.target.value)} maxLength={255} autoComplete="off" />
                    </Field>
                    <Field id="bill_date" label={t('supplier_bill.bill_date')} error={errors.bill_date}>
                        <DateInput value={data.bill_date} onChange={(v) => setData('bill_date', v)} />
                    </Field>
                    <Field id="bill_due_date" label={t('supplier_bill.due_date')} optional error={errors.due_date}>
                        <DateInput value={data.due_date} onChange={(v) => setData('due_date', v)} />
                    </Field>

                    <div className="space-y-4 rounded-xl border-2 border-stone-200 p-4 dark:border-stone-700">
                        <p className="text-base font-semibold text-stone-900 dark:text-stone-100">{t('supplier_bill.lines_title')}</p>
                        {data.items.map((line, index) => (
                            <div key={index} className="space-y-2 border-b border-stone-200 pb-4 last:border-0 last:pb-0 dark:border-stone-700" data-testid="bill-line">
                                <div className="flex items-start justify-between gap-2">
                                    <p className="text-sm font-medium text-stone-800 dark:text-stone-200">{t('supplier_bill.line_n', { n: index + 1 })}</p>
                                    {data.items.length > 1 && !line.purchase_order_item_id && (
                                        <Button type="button" variant="quiet" size="md" icon={TrashIcon} onClick={() => removeLine(index)}>{t('supplier_bill.remove_line')}</Button>
                                    )}
                                </div>
                                {line.purchase_order_item_id ? (
                                    <p className="text-sm text-stone-700 dark:text-stone-300">{line.description}</p>
                                ) : (
                                    <Field id={`bill_desc_${index}`} label={t('supplier_bill.description')} error={errors[`items.${index}.description`]}>
                                        <Input value={line.description} onChange={(e) => setLine(index, { description: e.target.value })} maxLength={255} autoComplete="off" />
                                    </Field>
                                )}
                                {!line.purchase_order_item_id && (
                                    <Field id={`bill_expense_${index}`} label={t('supplier_bill.expense_account')} optional>
                                        <Select value={line.expense_account_id} onChange={(e) => setLine(index, { expense_account_id: e.target.value })}>
                                            <option value="">{t('supplier_bill.default_expense_account')}</option>
                                            {expenseAccounts.map((a) => <option key={a.id} value={a.id}>{a.code} {a.name}</option>)}
                                        </Select>
                                    </Field>
                                )}
                                <div className="grid grid-cols-3 gap-2">
                                    <Field id={`bill_qty_${index}`} label={t('quotation.quantity')} error={errors[`items.${index}.quantity`]}>
                                        <Input inputMode="numeric" value={line.quantity} onChange={(e) => setLine(index, { quantity: e.target.value })} autoComplete="off" />
                                    </Field>
                                    <Field id={`bill_price_${index}`} label={t('quotation.unit_price')} error={errors[`items.${index}.unit_price`]}>
                                        <Input inputMode="decimal" value={line.unit_price} onChange={(e) => setLine(index, { unit_price: e.target.value })} autoComplete="off" />
                                    </Field>
                                    <Field id={`bill_tax_${index}`} label={t('quotation.tax_amount')} optional error={errors[`items.${index}.tax_amount`]}>
                                        <Input inputMode="decimal" value={line.tax_amount} onChange={(e) => setLine(index, { tax_amount: e.target.value })} autoComplete="off" />
                                    </Field>
                                </div>
                                {lineTotal(line) !== null && <p className="text-sm text-stone-700 dark:text-stone-300">{t('quotation.line_total', { amount: money(lineTotal(line)) })}</p>}
                            </div>
                        ))}
                        <Button type="button" variant="secondary" icon={PlusIcon} onClick={addLine}>{t('supplier_bill.add_line')}</Button>
                        {grandTotal > 0 && <p className="text-lg font-bold text-stone-900 dark:text-stone-100">{t('quotation.grand_total', { amount: money(grandTotal) })}</p>}
                    </div>

                    <Field id="bill_notes" label={t('supplier_bill.notes')} optional error={errors.notes}>
                        <TextArea id="bill_notes" value={data.notes} onChange={(e) => setData('notes', e.target.value)} />
                    </Field>

                    <Button type="submit" fullWidth busy={processing} disabled={!data.supplier_id || !linesValid}>{t('supplier_bill.save_button')}</Button>
                </form>
            </Page>
        </AuthenticatedLayout>
    );
}
