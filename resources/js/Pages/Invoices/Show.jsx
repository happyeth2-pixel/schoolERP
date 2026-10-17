import Card3D from '@/Components/Card3D';
import ParentInvoice from '@/Components/ParentInvoice';
import { DiscountDialog, WaiveDialog } from '@/Components/InvoiceAdjust';
import PaymentProofWizard from '@/Components/PaymentProofWizard';
import SplitMeter from '@/Components/SplitMeter';
import { Button, FlashMessages, useConfirm } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
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

function PaymentAccountInfo({ accounts }) {
    if (!accounts || accounts.length === 0) {
        return null;
    }

    return (
        <div className="space-y-2">
            <p className="text-sm font-semibold text-gray-700 dark:text-gray-300">Send payment to:</p>
            {accounts.map((account) => (
                <div key={account.id} className="flex items-center gap-3 rounded-lg border border-indigo-200 bg-indigo-50 p-3 text-sm dark:border-indigo-800 dark:bg-indigo-900/30">
                    {account.logo_url ? (
                        <img src={account.logo_url} alt={account.display_name} className="h-10 w-10 shrink-0 rounded-md bg-white object-contain p-1 shadow-sm" />
                    ) : (
                        <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-indigo-200 text-sm font-bold text-indigo-700 dark:bg-indigo-800 dark:text-indigo-200">
                            {account.display_name?.[0] ?? '?'}
                        </span>
                    )}
                    <div>
                        <p className="font-medium text-indigo-800 dark:text-indigo-300">{account.display_name}</p>
                        {account.type === 'mobile_money' ? (
                            <p className="text-indigo-700 dark:text-indigo-300">{account.account_number}</p>
                        ) : (
                            <p className="text-indigo-700 dark:text-indigo-300">
                                {account.account_number} {account.account_name ? `· ${account.account_name}` : ''}
                            </p>
                        )}
                    </div>
                </div>
            ))}
        </div>
    );
}

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

    return (
        <AuthenticatedLayout
            header={<h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">Invoice {invoice.invoice_number}</h2>}
        >
            <Head title={`Invoice ${invoice.invoice_number}`} />

            <div className="py-8">
                <div className="mx-auto max-w-2xl space-y-6 px-4 sm:px-6 lg:px-8">
                    <FlashMessages />

                    <Card3D glow="indigo" className="p-6">
                        <p className="text-sm text-gray-500">{invoice.student.name} · {invoice.period}</p>

                        <div className="mt-4 overflow-x-auto">
                        <table className="min-w-full">
                            <tbody>
                                {invoice.items.map((item) => (
                                    <tr key={item.id}>
                                        <td className="py-1 text-sm text-gray-700 dark:text-gray-300">{item.description}{item.quantity > 1 ? ` (${item.quantity} × ${money(item.unit_amount)})` : ''}</td>
                                        <td className="py-1 text-right text-sm text-gray-500">{money(item.amount)}</td>
                                    </tr>
                                ))}
                                <tr className="border-t border-gray-100 dark:border-gray-700">
                                    <td className="py-1 text-sm text-gray-500">Subtotal</td>
                                    <td className="py-1 text-right text-sm text-gray-500">{money(invoice.subtotal)}</td>
                                </tr>
                                {invoice.discount_amount > 0 && (
                                    <tr>
                                        <td className="py-1 text-sm text-gray-500">Discount {invoice.discount_reason ? `(${invoice.discount_reason}${invoice.discount_by ? `, by ${invoice.discount_by}` : ''})` : ''}</td>
                                        <td className="py-1 text-right text-sm text-gray-500">-{money(invoice.discount_amount)}</td>
                                    </tr>
                                )}
                                <tr className="font-medium">
                                    <td className="py-1 text-sm text-gray-900 dark:text-gray-100">Total billed</td>
                                    <td className="py-1 text-right text-sm text-gray-900 dark:text-gray-100">{money(invoice.total)}</td>
                                </tr>
                                {invoice.refunded > 0 && (
                                    <tr>
                                        <td className="py-1 text-sm text-gray-500">Refunded / reversed</td>
                                        <td className="py-1 text-right text-sm text-gray-500">{money(invoice.refunded)}</td>
                                    </tr>
                                )}
                                {invoice.is_waived && (
                                    <tr>
                                        <td className="py-1 text-sm text-amber-600">Waived{invoice.waiver_reason ? ` (${invoice.waiver_reason}${invoice.waived_by ? `, by ${invoice.waived_by}` : ''})` : ''}</td>
                                        <td className="py-1 text-right text-sm text-amber-600">-{money(invoice.waived)}</td>
                                    </tr>
                                )}
                                <tr>
                                    <td className="py-1 text-sm text-gray-500">Still owed</td>
                                    <td className="py-1 text-right text-sm text-gray-500">{money(invoice.balance)}</td>
                                </tr>
                            </tbody>
                        </table>
                        </div>

                        <div className="mt-4">
                            <SplitMeter bare label="Payment Progress" paid={invoice.paid} total={invoice.total} />
                        </div>

                        {canManage && (
                            <div className="mt-4 flex flex-wrap gap-3 border-t border-gray-100 pt-4 dark:border-gray-700" data-testid="adjust-actions">
                                <Button size="md" variant="secondary" onClick={() => setDialog('discount')}>{invoice.discount_amount > 0 ? t('adj.change_discount') : t('adj.give_discount')}</Button>
                                {invoice.is_waived
                                    ? <Button size="md" variant="secondary" onClick={removeWaiver}>{t('adj.remove_waiver')}</Button>
                                    : <Button size="md" variant="secondary" onClick={() => setDialog('waive')}>{t('adj.waive')}</Button>}
                            </div>
                        )}
                    </Card3D>

                    <Card3D glow="green" className="p-6">
                        <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
                            <h3 className="text-sm font-semibold uppercase tracking-wide text-gray-400">Payments</h3>
                            {canRecord && <Button as={Link} href={route('payments.record.create', invoice.id)} data-testid="record-payment">{t('rec.record_button')}</Button>}
                        </div>
                        <div className="space-y-2">
                            {invoice.payments.map((p) => (
                                <div key={p.id} className="flex flex-wrap items-center justify-between rounded-md border border-gray-100 p-3 text-sm dark:border-gray-700">
                                    <div>
                                        <span className="font-medium text-gray-900 dark:text-gray-100">{money(p.amount)} ETB</span>
                                        <span className="ml-2 text-gray-400">{p.method.replace('_', ' ')} · {p.reference_number ?? 'no ref'}</span>
                                        {p.channel === 'counter' && <p className="text-xs text-gray-500" data-testid="counter-note">{t('rec.at_school_note', { by: p.paid_by_name, who: p.submitted_by })}</p>}
                                        {p.rejection_reason && <p className="text-xs text-red-500">Rejected: {p.rejection_reason}</p>}
                                    </div>
                                    <div className="flex items-center gap-3">
                                        <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${PAYMENT_STATUS_STYLE[p.status]}`}>{p.status}</span>
                                        {p.has_proof && <a href={route('payments.proof', p.id)} target="_blank" className="text-indigo-600 hover:underline dark:text-indigo-400">Proof</a>}
                                        {p.status === 'verified' && <a href={route('payments.receipt', p.id)} className="text-indigo-600 hover:underline dark:text-indigo-400">Receipt</a>}
                                        {canRefund && p.refundable > 0 && (
                                            <Link href={route('payments.review', p.id)} className="text-amber-700 hover:underline dark:text-amber-400">
                                                {t('adj.refund_link')}
                                            </Link>
                                        )}
                                    </div>
                                    {p.refunds.length > 0 && (
                                        <ul className="mt-2 w-full space-y-1 border-t border-gray-100 pt-2 text-xs text-amber-700 dark:border-gray-700 dark:text-amber-400">
                                            {p.refunds.map((r) => (
                                                <li key={r.id}>
                                                    {r.kind === 'refund' ? 'Refunded' : 'Reversed'} {money(r.amount)} ETB — {r.reason} ({r.by}, {r.at}{r.reference ? `, ref ${r.reference}` : ''})
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </div>
                            ))}
                            {invoice.payments.length === 0 && <p className="text-sm text-gray-400">No payments submitted yet.</p>}
                        </div>

                        {isParent && invoice.balance > 0 && !invoice.is_waived && (
                            <div className="mt-4 space-y-3">
                                <PaymentAccountInfo accounts={paymentAccounts} />
                                <PaymentProofWizard invoiceId={invoice.id} balance={invoice.balance} hasAccounts={(paymentAccounts?.length ?? 0) > 0} />
                            </div>
                        )}
                    </Card3D>
                </div>
            </div>
            {canManage && <DiscountDialog open={dialog === 'discount'} invoice={invoice} limits={limits} canApprove={canApprove} onClose={() => setDialog(null)} />}
            {canManage && <WaiveDialog open={dialog === 'waive'} invoice={invoice} limits={limits} canApprove={canApprove} onClose={() => setDialog(null)} />}
            {confirmDialog}
        </AuthenticatedLayout>
    );
}
