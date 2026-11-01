import Modal from '@/Components/Modal';
import { Alert, BackLink, Button, Card, Field, FlashMessages, Input, Page, SectionHeader, StatusBadge, TextArea } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

const STATUS_TONE = { draft: 'neutral', submitted: 'warning', approved: 'info', rejected: 'danger', partially_issued: 'info', fully_issued: 'success', completed: 'success' };

function RejectDialog({ open, requisition, onClose }) {
    const { t } = useTranslation();
    const { data, setData, post, processing, errors, reset } = useForm({ reason: '' });

    const submit = (e) => {
        e.preventDefault();
        post(route('requisitions.reject', requisition.id), { preserveScroll: true, onSuccess: () => { reset(); onClose(); } });
    };

    return (
        <Modal show={open} onClose={onClose} maxWidth="lg">
            {open && (
                <form onSubmit={submit} className="space-y-6 p-6" data-testid="reject-form">
                    <h2 className="text-2xl font-bold text-stone-900 dark:text-stone-100">{t('req.reject_title', { number: requisition.number })}</h2>
                    <Field id="reject_reason" label={t('req.reject_reason')} error={errors.reason}>
                        <TextArea id="reject_reason" value={data.reason} onChange={(e) => setData('reason', e.target.value)} />
                    </Field>
                    <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        <Button type="button" variant="secondary" onClick={onClose}>{t('common.cancel')}</Button>
                        <Button type="submit" variant="danger" busy={processing} disabled={data.reason.trim() === ''}>{t('req.reject_button')}</Button>
                    </div>
                </form>
            )}
        </Modal>
    );
}

function IssueDialog({ open, requisition, line, onClose }) {
    const { t } = useTranslation();
    const { data, setData, post, processing, errors, reset, clearErrors } = useForm({ requisition_item_id: line?.id ?? '', quantity: '' });

    const submit = (e) => {
        e.preventDefault();
        post(route('requisitions.issue', requisition.id), { preserveScroll: true, onSuccess: () => { reset(); onClose(); } });
    };

    return (
        <Modal show={open} onClose={onClose} maxWidth="lg">
            {open && (
                <form onSubmit={submit} className="space-y-6 p-6" data-testid="requisition-issue-form">
                    <h2 className="text-2xl font-bold text-stone-900 dark:text-stone-100">{t('req.issue_title', { name: line?.item_name })}</h2>
                    <p className="text-base text-stone-700 dark:text-stone-300">{t('req.issue_remaining', { count: line?.remaining, unit: line?.unit })}</p>
                    <Field id="issue_qty" label={t('req.issue_quantity')} error={errors.quantity}>
                        <Input inputMode="numeric" value={data.quantity} onChange={(e) => { clearErrors('quantity'); setData('quantity', e.target.value); }} autoComplete="off" />
                    </Field>
                    <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        <Button type="button" variant="secondary" onClick={onClose}>{t('common.cancel')}</Button>
                        <Button type="submit" variant="success" busy={processing} disabled={!data.quantity}>{t('req.issue_button')}</Button>
                    </div>
                </form>
            )}
        </Modal>
    );
}

function ApproveForm({ requisition, onDone }) {
    const { t } = useTranslation();
    const initial = Object.fromEntries(requisition.items.map((i) => [i.id, String(i.quantity_requested)]));
    const { data, setData, post, processing, errors } = useForm({ quantities: initial });

    const submit = (e) => {
        e.preventDefault();
        post(route('requisitions.approve', requisition.id), { preserveScroll: true, onSuccess: onDone });
    };

    return (
        <Card as="form" onSubmit={submit} data-testid="approve-form">
            <SectionHeader as="h3" title={t('req.approve_title')} description={t('req.approve_desc')} />
            <div className="space-y-4">
                {requisition.items.map((i) => (
                    <Field key={i.id} id={`approve_qty_${i.id}`} label={`${i.item_name} (${t('req.requested_n', { count: i.quantity_requested, unit: i.unit })})`} error={errors[`quantities.${i.id}`]}>
                        <Input inputMode="numeric" value={data.quantities[i.id]} onChange={(e) => setData('quantities', { ...data.quantities, [i.id]: e.target.value })} autoComplete="off" />
                    </Field>
                ))}
            </div>
            <Button type="submit" variant="success" fullWidth busy={processing} className="mt-6">{t('req.approve_button')}</Button>
        </Card>
    );
}

export default function Show({ requisition, canSubmit, canApprove, canIssue }) {
    const { t } = useTranslation();
    const [rejecting, setRejecting] = useState(false);
    const [issuingLine, setIssuingLine] = useState(null);
    const submitForm = useForm({});

    const submit = () => submitForm.post(route('requisitions.submit', requisition.id), { preserveScroll: true });

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">{requisition.number}</h2>}>
            <Page title={requisition.number} back={<BackLink href={route('requisitions.index')}>{t('req.back_to_list')}</BackLink>}>
                <FlashMessages />

                <Card>
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p className="text-lg font-semibold text-stone-900 dark:text-stone-100">{requisition.purpose}</p>
                            <p className="text-sm text-stone-600 dark:text-stone-400">{requisition.store_name}{requisition.department ? ` · ${requisition.department}` : ''} · {t('req.by_x_on_y', { name: requisition.requester, date: requisition.created_at })}</p>
                        </div>
                        <StatusBadge tone={STATUS_TONE[requisition.status]} label={t(`req.status_${requisition.status}`)} />
                    </div>
                    {requisition.status === 'rejected' && requisition.rejection_reason && (
                        <Alert tone="danger" title={t('req.rejected_reason_title')} className="mt-4">{requisition.rejection_reason}</Alert>
                    )}
                </Card>

                <Card>
                    <SectionHeader as="h3" title={t('req.items_title')} />
                    <ul className="divide-y divide-stone-200 dark:divide-stone-700" data-testid="requisition-items">
                        {requisition.items.map((i) => (
                            <li key={i.id} className="py-3" data-testid="requisition-item-row">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <span className="text-base font-semibold text-stone-900 dark:text-stone-100">{i.item_name}</span>
                                    <span className="text-base text-stone-800 dark:text-stone-200">
                                        {t('req.requested_n', { count: i.quantity_requested, unit: i.unit })}
                                        {i.quantity_approved !== null && ` · ${t('req.approved_n', { count: i.quantity_approved })}`}
                                        {i.quantity_issued > 0 && ` · ${t('req.issued_n', { count: i.quantity_issued })}`}
                                    </span>
                                </div>
                                {i.notes && <p className="text-sm text-stone-600 dark:text-stone-400">{i.notes}</p>}
                                {canIssue && i.remaining > 0 && (
                                    <Button size="md" variant="secondary" className="mt-2" onClick={() => setIssuingLine(i)}>{t('req.issue_line_button')}</Button>
                                )}
                            </li>
                        ))}
                    </ul>
                </Card>

                {canSubmit && (
                    <Button fullWidth busy={submitForm.processing} onClick={submit}>{t('req.submit_button')}</Button>
                )}

                {canApprove && (
                    <>
                        <ApproveForm requisition={requisition} onDone={() => {}} />
                        <Button variant="danger" fullWidth onClick={() => setRejecting(true)}>{t('req.reject_button')}</Button>
                    </>
                )}
            </Page>

            <RejectDialog open={rejecting} requisition={requisition} onClose={() => setRejecting(false)} />
            {/* keyed by line id: useForm only captures its initial requisition_item_id once, so a fresh
                instance is needed whenever a different line's dialog opens */}
            <IssueDialog key={`issue-${issuingLine?.id ?? 'none'}`} open={issuingLine !== null} requisition={requisition} line={issuingLine} onClose={() => setIssuingLine(null)} />
        </AuthenticatedLayout>
    );
}
