import Card3D from '@/Components/Card3D';
import ParentInvoice from '@/Components/ParentInvoice';
import { DiscountDialog, WaiveDialog } from '@/Components/InvoiceAdjust';
import SplitMeter from '@/Components/SplitMeter';
import { BackLink, Button, FlashMessages, useConfirm } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { TrashIcon } from '@heroicons/react/24/outline';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

function money(n) {
    return Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

const PAYMENT_STATUS_STYLE = {
    pending: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
    verified: 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300',
    rejected: 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
};

export default function Show({ invoice, canManage, canRecord, paymentAccounts, limits, canApprove, canRefund, refundKinds }) {
    const { t } = useTranslation();
    const { auth } = usePage().props;
    const [dialog, setDialog] = useState(null);   // 'discount' | 'waive'
    const [ask, confirmDialog] = useConfirm();
    const isParent = auth.role === 'parent';

    // a parent gets a simple bill (what is left, how to pay, their payments); none of the staff tools below
    if (isParent) return <ParentInvoice invoice={invoice} paymentAccounts={paymentAccounts} />;

    const removeWaiver = async () => {
        const ok = await ask({
            title: t('adj.unwaive_title', { number: invoice.invoice_number }),
            body: <p>{t('adj.unwaive_body', { amount: money(invoice.waived) })}</p>,
            confirmLabel: t('adj.unwaive_button'),
            tone: 'primary',
        });
        if (ok) router.post(route('invoices.toggle-waive', invoice.id), {}, { preserveScroll: true });
    };

    // Only for a bill nobody has paid anything toward yet (any payment -- even a rejected one --
    // routes them to Waive instead): a real delete, so the month/term it was for becomes billable
    // again right away, which a waiver alone would not do.
    const canDelete = invoice.payments.length === 0;
    const deleteInvoice = async () => {
        const ok = await ask({
            title: t('adj.delete_title', { number: invoice.invoice_number }),
            body: <p>{t('adj.delete_body')}</p>,
            confirmLabel: t('adj.delete_button'),
            tone: 'danger',
        });
        if (ok) router.delete(route('invoices.destroy', invoice.id));
    };

    return (
        <AuthenticatedLayout
            header={<h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">Invoice {invoice.invoice_number}</h2>}
        >
            <Head title={`Invoice ${invoice.invoice_number}`} />

            <div className="py-8">
                <div className="mx-auto max-w-2xl space-y-6 px-4 sm:px-6 lg:px-8">
                    <BackLink href={route('invoices.index')}>{t('invoices.back_to_list')}</BackLink>
                    <FlashMessages />

                    <Card3D glow="indigo" className="p-6">
                        <p className="text-sm text-stone-500">{invoice.student.name} · {invoice.period}</p>

                        <div className="mt-4 overflow-x-auto">
                        <table className="min-w-full">
                            <tbody>
                                {invoice.items.map((item) => (
                                    <tr key={item.id}>
                                        <td className="py-1 text-sm text-stone-700 dark:text-stone-300">{item.description}{item.quantity > 1 ? ` (${item.quantity} × ${money(item.unit_amount)})` : ''}</td>
                                        <td className="py-1 text-right text-sm text-stone-500">{money(item.amount)}</td>
                                    </tr>
                                ))}
                                <tr className="border-t border-stone-100 dark:border-stone-700">
                                    <td className="py-1 text-sm text-stone-500">Subtotal</td>
                                    <td className="py-1 text-right text-sm text-stone-500">{money(invoice.subtotal)}</td>
                                </tr>
                                {invoice.discount_amount > 0 && (
                                    <tr>
                                        <td className="py-1 text-sm text-stone-500">Discount {invoice.discount_reason ? `(${invoice.discount_reason}${invoice.discount_by ? `, by ${invoice.discount_by}` : ''})` : ''}</td>
                                        <td className="py-1 text-right text-sm text-stone-500">-{money(invoice.discount_amount)}</td>
                                    </tr>
                                )}
                                <tr className="font-medium">
                                    <td className="py-1 text-sm text-stone-900 dark:text-stone-100">Total billed</td>
                                    <td className="py-1 text-right text-sm text-stone-900 dark:text-stone-100">{money(invoice.total)}</td>
                                </tr>
                                {invoice.refunded > 0 && (
                                    <tr>
                                        <td className="py-1 text-sm text-stone-500">Refunded / reversed</td>
                                        <td className="py-1 text-right text-sm text-stone-500">{money(invoice.refunded)}</td>
                                    </tr>
                                )}
                                {invoice.is_waived && (
                                    <tr>
                                        <td className="py-1 text-sm text-amber-600">Waived{invoice.waiver_reason ? ` (${invoice.waiver_reason}${invoice.waived_by ? `, by ${invoice.waived_by}` : ''})` : ''}</td>
                                        <td className="py-1 text-right text-sm text-amber-600">-{money(invoice.waived)}</td>
                                    </tr>
                                )}
                                <tr>
                                    <td className="py-1 text-sm text-stone-500">Still owed</td>
                                    <td className="py-1 text-right text-sm text-stone-500">{money(invoice.balance)}</td>
                                </tr>
                            </tbody>
                        </table>
                        </div>

                        <div className="mt-4">
                            <SplitMeter bare label="Payment Progress" paid={invoice.paid} total={invoice.total} />
                        </div>

                        {canManage && (
                            <div className="mt-4 flex flex-wrap gap-3 border-t border-stone-100 pt-4 dark:border-stone-700" data-testid="adjust-actions">
                                <Button size="md" variant="secondary" onClick={() => setDialog('discount')}>{invoice.discount_amount > 0 ? t('adj.change_discount') : t('adj.give_discount')}</Button>
                                {invoice.is_waived
                                    ? <Button size="md" variant="secondary" onClick={removeWaiver}>{t('adj.remove_waiver')}</Button>
                                    : <Button size="md" variant="secondary" onClick={() => setDialog('waive')}>{t('adj.waive')}</Button>}
                                {canDelete && (
                                    <Button size="md" variant="danger" icon={TrashIcon} onClick={deleteInvoice} data-testid="delete-invoice">
                                        {t('adj.delete_bill')}
                                    </Button>
                                )}
                            </div>
                        )}
                    </Card3D>

                    <Card3D glow="green" className="p-6">
                        <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
                            <h3 className="text-sm font-semibold uppercase tracking-wide text-stone-400">Payments</h3>
                            {canRecord && <Button as={Link} href={route('payments.record.create', invoice.id)} data-testid="record-payment">{t('rec.record_button')}</Button>}
                        </div>
                        <div className="space-y-2">
                            {invoice.payments.map((p) => (
                                <div key={p.id} className="flex flex-wrap items-center justify-between rounded-md border border-stone-100 p-3 text-sm dark:border-stone-700">
                                    <div>
                                        <span className="font-medium text-stone-900 dark:text-stone-100">{money(p.amount)} ETB</span>
                                        <span className="ml-2 text-stone-400">{p.method.replace('_', ' ')} · {p.reference_number ?? 'no ref'}</span>
                                        {p.channel === 'counter' && <p className="text-xs text-stone-500" data-testid="counter-note">{t('rec.at_school_note', { by: p.paid_by_name, who: p.submitted_by })}</p>}
                                        {p.rejection_reason && <p className="text-xs text-red-500">Rejected: {p.rejection_reason}</p>}
                                    </div>
                                    <div className="flex items-center gap-3">
                                        <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${PAYMENT_STATUS_STYLE[p.status]}`}>{p.status}</span>
                                        {p.has_proof && <a href={route('payments.proof', p.id)} target="_blank" className="text-brand-600 hover:underline dark:text-brand-400">Proof</a>}
                                        {p.status === 'verified' && <a href={route('payments.receipt', p.id)} className="text-brand-600 hover:underline dark:text-brand-400">Receipt</a>}
                                        {canRefund && p.refundable > 0 && (
                                            <Link href={route('payments.review', p.id)} className="text-amber-700 hover:underline dark:text-amber-400">
                                                {t('adj.refund_link')}
                                            </Link>
                                        )}
                                    </div>
                                    {p.refunds.length > 0 && (
                                        <ul className="mt-2 w-full space-y-1 border-t border-stone-100 pt-2 text-xs text-amber-700 dark:border-stone-700 dark:text-amber-400">
                                            {p.refunds.map((r) => (
                                                <li key={r.id}>
                                                    {r.kind === 'refund' ? 'Refunded' : 'Reversed'} {money(r.amount)} ETB — {r.reason} ({r.by}, {r.at}{r.reference ? `, ref ${r.reference}` : ''})
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </div>
                            ))}
                            {invoice.payments.length === 0 && <p className="text-sm text-stone-400">No payments submitted yet.</p>}
                        </div>
                    </Card3D>
                </div>
            </div>
            {canManage && <DiscountDialog open={dialog === 'discount'} invoice={invoice} limits={limits} canApprove={canApprove} onClose={() => setDialog(null)} />}
            {canManage && <WaiveDialog open={dialog === 'waive'} invoice={invoice} limits={limits} canApprove={canApprove} onClose={() => setDialog(null)} />}
            {confirmDialog}
        </AuthenticatedLayout>
    );
}
