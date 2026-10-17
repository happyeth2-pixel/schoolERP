import Modal from '@/Components/Modal';
import PurchaseOrderDialog from '@/Components/PurchaseOrderDialog';
import QuotationDialog from '@/Components/QuotationDialog';
import { Alert, BackLink, Button, Card, EmptyState, Field, FlashMessages, Page, SectionHeader, StatusBadge, TextArea, useConfirm } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { ClipboardDocumentListIcon, DocumentTextIcon } from '@heroicons/react/24/outline';
import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

const money = (n) => Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const STATUS_TONE = { draft: 'neutral', submitted: 'warning', approved: 'success', rejected: 'danger' };

function RejectDialog({ open, request, onClose }) {
    const { t } = useTranslation();
    const { data, setData, post, processing, errors, reset } = useForm({ reason: '' });

    const submit = (e) => {
        e.preventDefault();
        post(route('purchase-requests.reject', request.id), { preserveScroll: true, onSuccess: () => { reset(); onClose(); } });
    };

    return (
        <Modal show={open} onClose={onClose} maxWidth="lg">
            {open && (
                <form onSubmit={submit} className="space-y-6 p-6" data-testid="reject-form">
                    <h2 className="text-2xl font-bold text-gray-900 dark:text-gray-100">{t('pr.reject_title', { number: request.number })}</h2>
                    <Field id="reject_reason" label={t('pr.reject_reason')} error={errors.reason}>
                        <TextArea id="reject_reason" value={data.reason} onChange={(e) => setData('reason', e.target.value)} />
                    </Field>
                    <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        <Button type="button" variant="secondary" onClick={onClose}>{t('common.cancel')}</Button>
                        <Button type="submit" variant="danger" busy={processing} disabled={data.reason.trim() === ''}>{t('pr.reject_button')}</Button>
                    </div>
                </form>
            )}
        </Modal>
    );
}

export default function Show({ request, canSubmit, canApprove, canManageQuotations, quotations, suppliers, canManagePurchaseOrders, purchaseOrders }) {
    const { t } = useTranslation();
    const [rejecting, setRejecting] = useState(false);
    const [addingQuotation, setAddingQuotation] = useState(false);
    const [addingPurchaseOrder, setAddingPurchaseOrder] = useState(false);
    const [selecting, setSelecting] = useState(null);
    const [removing, setRemoving] = useState(null);
    const selectedQuotation = quotations.find((q) => q.selected) ?? null;
    const canAddPurchaseOrder = canManagePurchaseOrders && request.items.some((i) => i.remaining_to_order > 0);
    const [ask, confirmDialog] = useConfirm();
    const submitForm = useForm({});
    const approveForm = useForm({});

    const submit = () => submitForm.post(route('purchase-requests.submit', request.id), { preserveScroll: true });
    const approve = () => approveForm.post(route('purchase-requests.approve', request.id), { preserveScroll: true });

    const selectQuotation = (quotation) => {
        setSelecting(quotation.id);
        router.post(route('quotations.select', quotation.id), {}, { preserveScroll: true, onFinish: () => setSelecting(null) });
    };

    const removeQuotation = async (quotation) => {
        const confirmed = await ask({
            title: t('quotation.remove_title'),
            body: t('quotation.remove_body', { supplier: quotation.supplier_name }),
            confirmLabel: t('quotation.remove_button'),
        });
        if (!confirmed) return;
        setRemoving(quotation.id);
        router.delete(route('quotations.destroy', quotation.id), { preserveScroll: true, onFinish: () => setRemoving(null) });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">{request.number}</h2>}>
            <Page title={request.number} back={<BackLink href={route('purchase-requests.index')}>{t('pr.back_to_list')}</BackLink>}>
                <FlashMessages />

                <Card>
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p className="text-lg font-semibold text-gray-900 dark:text-gray-100">{request.purpose}</p>
                            <p className="text-sm text-gray-600 dark:text-gray-400">{request.department ? `${request.department} · ` : ''}{t('pr.by_x_on_y', { name: request.requester, date: request.created_at })}</p>
                        </div>
                        <StatusBadge tone={STATUS_TONE[request.status]} label={t(`pr.status_${request.status}`)} />
                    </div>
                    {request.estimated_total > 0 && (
                        <p className="mt-3 text-xl font-bold text-gray-900 dark:text-gray-100">{t('pr.estimated_total_x', { amount: money(request.estimated_total) })}</p>
                    )}
                    {request.needs_large_approval && request.status === 'submitted' && (
                        <Alert tone="info" title={t('pr.needs_large_approval_title')} className="mt-3" />
                    )}
                    {request.status === 'rejected' && request.rejection_reason && (
                        <Alert tone="danger" title={t('pr.rejected_reason_title')} className="mt-4">{request.rejection_reason}</Alert>
                    )}
                    {request.notes && <p className="mt-3 text-base text-gray-700 dark:text-gray-300">{request.notes}</p>}
                </Card>

                <Card>
                    <SectionHeader as="h3" title={t('pr.items_title')} />
                    <ul className="divide-y divide-gray-200 dark:divide-gray-700" data-testid="pr-items">
                        {request.items.map((i) => (
                            <li key={i.id} className="py-3" data-testid="pr-item-row">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <span className="text-base font-semibold text-gray-900 dark:text-gray-100">{i.description}</span>
                                    <span className="text-base text-gray-800 dark:text-gray-200">{t('pr.quantity_n', { count: i.quantity })}{i.estimated_total !== null && ` · ${money(i.estimated_total)} ETB`}</span>
                                </div>
                                {i.notes && <p className="text-sm text-gray-600 dark:text-gray-400">{i.notes}</p>}
                            </li>
                        ))}
                    </ul>
                </Card>

                {request.status === 'approved' && (
                    <Card>
                        <SectionHeader
                            as="h3"
                            title={t('quotation.section_title')}
                            action={canManageQuotations && <Button size="sm" onClick={() => setAddingQuotation(true)}>{t('quotation.add_button')}</Button>}
                        />
                        {quotations.length === 0 ? (
                            <EmptyState icon={DocumentTextIcon} title={t('quotation.empty_title')}>
                                {t('quotation.empty_body')}
                            </EmptyState>
                        ) : (
                            <ul className="divide-y divide-gray-200 dark:divide-gray-700" data-testid="quotation-list">
                                {quotations.map((q) => (
                                    <li key={q.id} className="space-y-2 py-4" data-testid="quotation-row">
                                        <div className="flex flex-wrap items-start justify-between gap-2">
                                            <div>
                                                <p className="text-base font-semibold text-gray-900 dark:text-gray-100">{q.supplier_name}</p>
                                                <p className="text-sm text-gray-600 dark:text-gray-400">
                                                    {q.reference ? `${q.reference} · ` : ''}{t('quotation.dated_x', { date: q.quotation_date })}
                                                </p>
                                            </div>
                                            {q.selected && <StatusBadge tone="success" label={t('quotation.selected_label')} />}
                                        </div>
                                        <p className="text-lg font-bold text-gray-900 dark:text-gray-100">{money(q.total)} ETB</p>
                                        {q.delivery_info && <p className="text-sm text-gray-700 dark:text-gray-300">{q.delivery_info}</p>}
                                        {q.notes && <p className="text-sm text-gray-600 dark:text-gray-400">{q.notes}</p>}
                                        {canManageQuotations && (
                                            <div className="flex flex-wrap gap-3 pt-1">
                                                {!q.selected && (
                                                    <Button size="sm" variant="success" busy={selecting === q.id} onClick={() => selectQuotation(q)}>
                                                        {t('quotation.select_button')}
                                                    </Button>
                                                )}
                                                {!q.selected && (
                                                    <Button size="sm" variant="danger" busy={removing === q.id} onClick={() => removeQuotation(q)}>
                                                        {t('quotation.remove_button')}
                                                    </Button>
                                                )}
                                            </div>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>
                )}

                {request.status === 'approved' && (
                    <Card>
                        <SectionHeader
                            as="h3"
                            title={t('purchase_order.section_title')}
                            action={canAddPurchaseOrder && <Button size="sm" onClick={() => setAddingPurchaseOrder(true)}>{t('purchase_order.add_button')}</Button>}
                        />
                        {purchaseOrders.length === 0 ? (
                            <EmptyState icon={ClipboardDocumentListIcon} title={t('purchase_order.empty_title')}>
                                {t('purchase_order.empty_body')}
                            </EmptyState>
                        ) : (
                            <ul className="divide-y divide-gray-200 dark:divide-gray-700" data-testid="po-summary-list">
                                {purchaseOrders.map((po) => (
                                    <li key={po.id} className="flex flex-wrap items-center justify-between gap-2 py-3" data-testid="po-summary-row">
                                        <div>
                                            <Link href={route('purchase-orders.show', po.id)} className="text-base font-semibold text-gray-900 hover:underline dark:text-gray-100">{po.number}</Link>
                                            <p className="text-sm text-gray-600 dark:text-gray-400">{po.supplier_name} · {money(po.total)} ETB</p>
                                        </div>
                                        <StatusBadge
                                            tone={po.status === 'cancelled' ? 'danger' : po.status === 'received' ? 'success' : po.status === 'sent' || po.status === 'partially_received' ? 'warning' : 'neutral'}
                                            label={t(`purchase_order.status_${po.status}`)}
                                        />
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>
                )}

                {canSubmit && <Button fullWidth busy={submitForm.processing} onClick={submit}>{t('pr.submit_button')}</Button>}

                {canApprove && (
                    <div className="flex flex-col gap-3 sm:flex-row">
                        <Button variant="success" fullWidth busy={approveForm.processing} onClick={approve}>{t('pr.approve_button')}</Button>
                        <Button variant="danger" fullWidth onClick={() => setRejecting(true)}>{t('pr.reject_button')}</Button>
                    </div>
                )}
            </Page>

            <RejectDialog open={rejecting} request={request} onClose={() => setRejecting(false)} />
            <QuotationDialog open={addingQuotation} purchaseRequest={request} suppliers={suppliers} onClose={() => setAddingQuotation(false)} />
            <PurchaseOrderDialog
                key={`po-${request.items.map((i) => `${i.id}:${i.remaining_to_order}`).join(',')}`}
                open={addingPurchaseOrder}
                purchaseRequest={request}
                suppliers={suppliers}
                selectedQuotation={selectedQuotation}
                onClose={() => setAddingPurchaseOrder(false)}
            />
            {confirmDialog}
        </AuthenticatedLayout>
    );
}
