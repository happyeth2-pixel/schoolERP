import PaymentProofWizard from '@/Components/PaymentProofWizard';
import { money } from '@/Components/ParentParts';
import { Alert, BackLink, Button, Card, FlashMessages, Page, StatusBadge } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { ArrowDownTrayIcon, EyeIcon } from '@heroicons/react/24/outline';
import { usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

// A bill, as a parent needs it: how much is left to pay (huge), what was billed and paid, how to pay, and
// the parent's own payments with their state in words. "Which account to send to" used to be its own
// list here, separate from "tell us how you paid" lower down -- now the wizard (PaymentProofWizard) shows
// the right account the moment a payment method is picked, so there is only one place to look, not two.
// The same invoice data and the same proof wizard as the staff page; none of the staff tools (discount,
// waiver, refund) are here.

const PAY_TONE = { pending: 'warning', verified: 'success', rejected: 'danger' };

export default function ParentInvoice({ invoice, paymentAccounts }) {
    const { t } = useTranslation();
    const { locale } = usePage().props;
    const owes = invoice.balance > 0 && !invoice.is_waived;
    const state = invoice.is_waived ? 'waived' : invoice.balance <= 0 ? 'paid' : invoice.paid > 0 ? 'partially_paid' : 'unpaid';
    const title = `${invoice.student.name} — ${invoice.period}`;
    const waiting = invoice.payments.filter((p) => p.status === 'pending');
    const day = (iso) => new Date(`${iso}T00:00:00`).toLocaleDateString(locale ?? undefined, { day: 'numeric', month: 'short', year: 'numeric' });

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">{t('parent.bill_title')}</h2>}>
            <Page title={title} width="default" back={<BackLink big href={route('dashboard')}>{t('parent.back_home')}</BackLink>}>
                <FlashMessages />

                <Card data-testid="bill-summary">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <p className="text-lg font-semibold text-stone-900 dark:text-stone-100">{owes ? t('parent.fees_left_label') : invoice.is_waived ? t('parent.bill_waived') : t('parent.fees_all_paid')}</p>
                        <StatusBadge status={state} label={t(`parent.fee_status_${state}`)} />
                    </div>
                    <p className="text-5xl font-bold text-stone-900 dark:text-stone-100" data-testid="bill-left">{money(invoice.balance)} <span className="text-2xl font-semibold text-stone-600 dark:text-stone-400">ETB</span></p>
                    <dl className="mt-4 grid grid-cols-2 gap-3">
                        <div className="rounded-xl bg-stone-50 p-3 dark:bg-stone-900/40"><dt className="text-sm text-stone-600 dark:text-stone-400">{t('parent.fees_total')}</dt><dd className="text-xl font-bold text-stone-900 dark:text-stone-100" data-testid="bill-total">{money(invoice.total)}</dd></div>
                        <div className="rounded-xl bg-stone-50 p-3 dark:bg-stone-900/40"><dt className="text-sm text-stone-600 dark:text-stone-400">{t('parent.fees_paid')}</dt><dd className="text-xl font-bold text-stone-900 dark:text-stone-100" data-testid="bill-paid">{money(invoice.paid)}</dd></div>
                    </dl>
                    {invoice.refunded > 0 && <p className="mt-3 text-base text-stone-800 dark:text-stone-200">{t('parent.bill_refunded', { amount: money(invoice.refunded) })}</p>}
                    {owes && invoice.due_date && (
                        <div className="mt-4">
                            {invoice.is_overdue
                                ? <Alert tone="danger" title={t('parent.bill_overdue_title')}>{t('parent.bill_overdue_body', { date: day(invoice.due_date) })}</Alert>
                                : <p className="text-base text-stone-700 dark:text-stone-300" data-testid="bill-due">{t('parent.bill_due_on', { date: day(invoice.due_date) })}</p>}
                        </div>
                    )}
                    {waiting.length > 0 && <div className="mt-4"><Alert tone="info" title={t('parent.fees_waiting_title', { count: waiting.length })}>{t('parent.fees_waiting_body', { amount: money(waiting.reduce((s, p) => s + Number(p.amount), 0)) })}</Alert></div>}
                    <p className="mt-4 text-sm text-stone-400" data-testid="bill-number">{t('parent.bill_number', { number: invoice.invoice_number })}</p>
                </Card>

                <Card as="section" aria-label={t('parent.bill_for')}>
                    <h2 className="mb-2 text-xl font-bold text-stone-900 dark:text-stone-100">{t('parent.bill_for')}</h2>
                    <ul className="divide-y divide-stone-200 dark:divide-stone-700">
                        {invoice.items.map((i) => (
                            <li key={i.id} className="flex justify-between gap-3 py-2 text-lg text-stone-900 dark:text-stone-100"><span>{i.description}{i.quantity > 1 ? ` (${i.quantity} × ${money(i.unit_amount)})` : ''}</span><span className="font-semibold">{money(i.amount)}</span></li>
                        ))}
                        {invoice.discount_amount > 0 && <li className="flex justify-between gap-3 py-2 text-lg text-stone-900 dark:text-stone-100"><span>{t('parent.bill_discount')}</span><span className="font-semibold">−{money(invoice.discount_amount)}</span></li>}
                    </ul>
                </Card>

                {owes && (
                    <Card as="section" aria-label={t('parent.pay_title')} data-testid="how-to-pay">
                        <h2 className="mb-4 text-xl font-bold text-stone-900 dark:text-stone-100">{t('parent.pay_title')}</h2>
                        <PaymentProofWizard invoiceId={invoice.id} balance={invoice.balance} accounts={paymentAccounts ?? []} />
                    </Card>
                )}

                <section aria-label={t('parent.payments_title')}>
                    <h2 className="mb-3 text-xl font-bold text-stone-900 dark:text-stone-100">{t('parent.payments_title')}</h2>
                    {invoice.payments.length === 0 ? (
                        <Card><p className="text-lg text-stone-800 dark:text-stone-200">{t('parent.payments_none')}</p></Card>
                    ) : (
                        <ul className="space-y-3" data-testid="payments">
                            {invoice.payments.map((p) => (
                                <li key={p.id} className="rounded-2xl border-2 border-stone-200 bg-white p-4 dark:border-stone-700 dark:bg-stone-800" data-status={p.status}>
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <p className="text-2xl font-bold text-stone-900 dark:text-stone-100">{money(p.amount)} ETB</p>
                                        <StatusBadge tone={PAY_TONE[p.status]} status={p.status} label={t(`parent.pay_status_${p.status}`)} />
                                    </div>
                                    <p className="mt-1 text-base text-stone-800 dark:text-stone-200">{t(`verify.method_${p.method}`)}{p.reference_number ? ` · ${t('verify.reference')}: ${p.reference_number}` : ''}</p>
                                    {p.status === 'pending' && <p className="mt-1 text-base text-stone-700 dark:text-stone-300">{t('parent.pay_pending_note')}</p>}
                                    {p.status === 'rejected' && <p className="mt-1 text-base font-medium text-red-800 dark:text-red-300">{t('parent.pay_rejected_reason', { reason: p.rejection_reason })} {t('parent.pay_rejected_next')}</p>}
                                    {p.status === 'verified' && p.receipt_number && <p className="mt-1 text-base text-stone-800 dark:text-stone-200">{t('parent.pay_receipt', { number: p.receipt_number })}</p>}
                                    {p.refunds.length > 0 && <ul className="mt-1 text-base text-stone-800 dark:text-stone-200">{p.refunds.map((r) => <li key={r.id}>{t('parent.pay_returned', { amount: money(r.amount) })}</li>)}</ul>}
                                    <div className="mt-3 flex flex-wrap gap-3">
                                        {p.status === 'verified' && <Button as="a" href={route('payments.receipt', p.id)} size="md" icon={ArrowDownTrayIcon}>{t('parent.pay_download_receipt')}</Button>}
                                        {p.has_proof && <Button as="a" href={route('payments.proof', p.id)} target="_blank" rel="noreferrer" variant="secondary" size="md" icon={EyeIcon}>{t('parent.pay_see_proof')}</Button>}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </Page>
        </AuthenticatedLayout>
    );
}
