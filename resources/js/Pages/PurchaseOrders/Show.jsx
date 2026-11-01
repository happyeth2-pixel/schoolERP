import GoodsReceiptDialog from '@/Components/GoodsReceiptDialog';
import Modal from '@/Components/Modal';
import { Alert, BackLink, Button, Card, EmptyState, Field, FlashMessages, Page, SectionHeader, StatusBadge, TextArea, useConfirm } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { ClipboardDocumentListIcon } from '@heroicons/react/24/outline';
import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

const money = (n) => Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const STATUS_TONE = { draft: 'neutral', sent: 'warning', partially_received: 'warning', received: 'success', cancelled: 'danger' };

function CancelDialog({ open, order, onClose }) {
    const { t } = useTranslation();
    const { data, setData, post, processing, errors, reset } = useForm({ reason: '' });

    const submit = (e) => {
        e.preventDefault();
        post(route('purchase-orders.cancel', order.id), { preserveScroll: true, onSuccess: () => { reset(); onClose(); } });
    };

    return (
        <Modal show={open} onClose={onClose} maxWidth="lg">
            {open && (
                <form onSubmit={submit} className="space-y-6 p-6" data-testid="cancel-po-form">
                    <h2 className="text-2xl font-bold text-stone-900 dark:text-stone-100">{t('purchase_order.cancel_title', { number: order.number })}</h2>
                    <Field id="cancel_po_reason" label={t('purchase_order.cancel_reason')} error={errors.reason}>
                        <TextArea id="cancel_po_reason" value={data.reason} onChange={(e) => setData('reason', e.target.value)} />
                    </Field>
                    <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        <Button type="button" variant="secondary" onClick={onClose}>{t('common.cancel')}</Button>
                        <Button type="submit" variant="danger" busy={processing} disabled={data.reason.trim() === ''}>{t('purchase_order.cancel_button')}</Button>
                    </div>
                </form>
            )}
        </Modal>
    );
}

export default function Show({ order, canManage, canManageGoodsReceipts, goodsReceipts, stores }) {
    const { t } = useTranslation();
    const [cancelling, setCancelling] = useState(false);
    const [recordingReceipt, setRecordingReceipt] = useState(false);
    const [ask, confirmDialog] = useConfirm();
    const sendForm = useForm({});

    const send = () => sendForm.post(route('purchase-orders.send', order.id), { preserveScroll: true });

    const remove = async () => {
        const confirmed = await ask({
            title: t('purchase_order.remove_title'),
            body: t('purchase_order.remove_body', { number: order.number }),
            confirmLabel: t('purchase_order.remove_button'),
        });
        if (confirmed) router.delete(route('purchase-orders.destroy', order.id));
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">{order.number}</h2>}>
            <Page title={order.number} back={<BackLink href={route('purchase-orders.index')}>{t('purchase_order.back_to_list')}</BackLink>}>
                <FlashMessages />
                <Card>
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p className="text-lg font-semibold text-stone-900 dark:text-stone-100">{order.supplier}</p>
                            <p className="text-sm text-stone-600 dark:text-stone-400">{t('purchase_order.from_request_x', { number: order.purchase_request.number })}</p>
                        </div>
                        <StatusBadge tone={STATUS_TONE[order.status]} label={t(`purchase_order.status_${order.status}`)} />
                    </div>
                    <p className="mt-3 text-xl font-bold text-stone-900 dark:text-stone-100">{money(order.total)} ETB</p>
                    <p className="text-sm text-stone-700 dark:text-stone-300">{t('purchase_order.dated_x', { date: order.order_date })}</p>
                    {order.expected_delivery_date && <p className="text-sm text-stone-700 dark:text-stone-300">{t('purchase_order.expected_delivery_x', { date: order.expected_delivery_date })}</p>}
                    {order.delivery_info && <p className="text-sm text-stone-700 dark:text-stone-300">{order.delivery_info}</p>}
                    {order.status === 'cancelled' && order.cancellation_reason && (
                        <Alert tone="danger" title={t('purchase_order.cancelled_reason_title')} className="mt-4">{order.cancellation_reason}</Alert>
                    )}
                    {order.notes && <p className="mt-3 text-base text-stone-700 dark:text-stone-300">{order.notes}</p>}
                </Card>

                <Card>
                    <SectionHeader as="h3" title={t('purchase_order.lines_title')} />
                    <ul className="divide-y divide-stone-200 dark:divide-stone-700" data-testid="po-items">
                        {order.items.map((i) => (
                            <li key={i.id} className="py-3" data-testid="po-item-row">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <span className="text-base font-semibold text-stone-900 dark:text-stone-100">{i.description}</span>
                                    <span className="text-base text-stone-800 dark:text-stone-200">{t('pr.quantity_n', { count: i.quantity })} · {money(i.total)} ETB</span>
                                </div>
                                <p className="text-sm text-stone-600 dark:text-stone-400">
                                    {t('purchase_order.received_of_ordered', { received: i.received_quantity, ordered: i.quantity })}
                                </p>
                            </li>
                        ))}
                    </ul>
                </Card>

                {(order.status === 'sent' || order.status === 'partially_received' || order.status === 'received') && (
                    <Card>
                        <SectionHeader
                            as="h3"
                            title={t('goods_receipt.section_title')}
                            action={canManageGoodsReceipts && <Button size="sm" onClick={() => setRecordingReceipt(true)}>{t('goods_receipt.add_button')}</Button>}
                        />
                        {goodsReceipts.length === 0 ? (
                            <EmptyState icon={ClipboardDocumentListIcon} title={t('goods_receipt.empty_title')}>
                                {t('goods_receipt.empty_body')}
                            </EmptyState>
                        ) : (
                            <ul className="divide-y divide-stone-200 dark:divide-stone-700" data-testid="gr-list">
                                {goodsReceipts.map((gr) => (
                                    <li key={gr.id} className="py-3" data-testid="gr-row">
                                        <p className="text-base font-semibold text-stone-900 dark:text-stone-100">{gr.number}</p>
                                        <p className="text-sm text-stone-600 dark:text-stone-400">
                                            {t('goods_receipt.into_store_on_date', { store: gr.store_name, date: gr.received_date })} · {t('goods_receipt.by_x', { name: gr.recorded_by })}
                                        </p>
                                        {gr.notes && <p className="text-sm text-stone-600 dark:text-stone-400">{gr.notes}</p>}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>
                )}

                {canManage && order.status === 'draft' && (
                    <div className="flex flex-col gap-3 sm:flex-row">
                        <Button variant="success" fullWidth busy={sendForm.processing} onClick={send}>{t('purchase_order.send_button')}</Button>
                        <Button variant="danger" fullWidth onClick={() => setCancelling(true)}>{t('purchase_order.cancel_button')}</Button>
                    </div>
                )}
                {canManage && order.status === 'draft' && (
                    <Button variant="secondary" fullWidth onClick={remove}>{t('purchase_order.remove_button')}</Button>
                )}
                {canManage && (order.status === 'sent' || order.status === 'partially_received') && (
                    <Button variant="danger" fullWidth onClick={() => setCancelling(true)}>{t('purchase_order.cancel_button')}</Button>
                )}
            </Page>

            <CancelDialog open={cancelling} order={order} onClose={() => setCancelling(false)} />
            <GoodsReceiptDialog
                key={`gr-${order.items.map((i) => `${i.id}:${i.remaining_to_receive}`).join(',')}`}
                open={recordingReceipt}
                order={order}
                stores={stores}
                onClose={() => setRecordingReceipt(false)}
            />
            {confirmDialog}
        </AuthenticatedLayout>
    );
}
