import ProofViewer from '@/Components/ProofViewer';
import { Alert, BackLink, Button, Card, ChoiceGroup, Disclosure, Field, FlashMessages, Input, Page, StatusBadge, SuccessPanel, TextArea, useConfirm } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { BanknotesIcon, BuildingLibraryIcon, CheckCircleIcon, DevicePhoneMobileIcon, PrinterIcon, QuestionMarkCircleIcon, XCircleIcon } from '@heroicons/react/24/outline';
import { Link, router, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';

// One payment, laid out to answer the accountant's five questions in order: WHO paid, HOW MUCH, FOR WHAT, HOW,
// and WHAT TO DO NEXT -- with the proof large beside it. Everything shown already existed on the payment, its
// invoice and its student. The decisions still go through the same endpoints (verify / reject / refund), which
// keep their own locks, balance checks, reason rule, notifications and audit entries.
// "Payment Received" = the existing "verify" of a parent's proof. A payment taken at the school desk is recorded
// through Payments/Record and lands here already verified (channel "counter"). The receipt is the existing PDF;
// nothing tax-related is added.

const METHOD_ICON = { bank_transfer: BuildingLibraryIcon, mobile_money: DevicePhoneMobileIcon, cash: BanknotesIcon, other: QuestionMarkCircleIcon };
const money = (n) => Number(n).toLocaleString(undefined, { maximumFractionDigits: 2 });
// the desk confirmation always shows two decimals, like the payment screen it came from
const money2 = (n) => Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

function Row({ label, children }) {
    return (
        <div className="py-3">
            <dt className="text-sm font-semibold uppercase tracking-wide text-stone-600 dark:text-stone-400">{label}</dt>
            <dd className="mt-1 text-lg text-stone-900 dark:text-stone-100">{children}</dd>
        </div>
    );
}

function RejectPanel({ payment, onCancel }) {
    const { t } = useTranslation();
    const { data, setData, post, processing, errors } = useForm({ rejection_reason: '' });
    const reasons = ['reason_1', 'reason_2', 'reason_3', 'reason_4'].map((k) => t(`verify.${k}`));
    const submit = (e) => {
        e.preventDefault();
        if (data.rejection_reason.trim() === '') return;
        post(route('payments.reject', payment.id));
    };

    return (
        <form onSubmit={submit} className="space-y-4 rounded-2xl border-2 border-red-300 bg-red-50 p-5 dark:border-red-800 dark:bg-red-900/20" data-testid="reject-panel">
            <h3 className="text-xl font-semibold text-red-950 dark:text-red-100">{t('verify.reject_title')}</h3>
            <div>
                <p className="mb-2 text-base font-medium text-stone-900 dark:text-stone-100">{t('verify.reject_pick')}</p>
                <div className="flex flex-wrap gap-2">
                    {reasons.map((r) => (
                        <button key={r} type="button" onClick={() => setData('rejection_reason', r)} className={`min-h-[48px] rounded-xl border-2 px-3 py-2 text-left text-base font-medium ${data.rejection_reason === r ? 'border-red-700 bg-red-700 text-white' : 'border-stone-400 bg-white text-stone-900 dark:border-stone-500 dark:bg-stone-800 dark:text-stone-100'}`}>{r}</button>
                    ))}
                </div>
            </div>
            <Field id="rejection_reason" label={t('verify.reject_reason_label')} hint={t('verify.reject_reason_hint')} error={errors.rejection_reason}>
                <TextArea rows={3} maxLength={255} value={data.rejection_reason} onChange={(e) => setData('rejection_reason', e.target.value)} />
            </Field>
            <p className="text-sm text-stone-700 dark:text-stone-300">{data.rejection_reason.length} / 255</p>
            <div className="flex flex-col gap-3 sm:flex-row">
                <Button type="submit" variant="danger" busy={processing} disabled={data.rejection_reason.trim() === ''} icon={XCircleIcon}>{t('verify.reject_button')}</Button>
                <Button variant="secondary" onClick={onCancel} disabled={processing}>{t('verify.reject_cancel')}</Button>
            </div>
            {data.rejection_reason.trim() === '' && <p className="text-sm font-medium text-stone-700 dark:text-stone-300" role="status">{t('verify.reject_why_off')}</p>}
        </form>
    );
}

function RefundPanel({ payment, refund }) {
    const { t } = useTranslation();
    const [ask, dialog] = useConfirm();
    const { data, setData, post, processing, errors } = useForm({ amount: refund.refundable, kind: 'refund', reason: '', reference: '' });
    const tooMuch = Number(data.amount) > refund.refundable + 0.001;
    const kinds = Object.entries(refund.kinds).map(([value, text]) => { const [label, ...rest] = text.split(' — '); return { value, label, hint: rest.join(' — ') }; });

    const submit = async (e) => {
        e.preventDefault();
        const isRefund = data.kind === 'refund';
        const ok = await ask({
            title: t(isRefund ? 'verify.refund_confirm_title' : 'verify.reversal_confirm_title', { amount: money(data.amount) }),
            body: <p>{t('verify.refund_confirm_body')}</p>,
            confirmLabel: t(isRefund ? 'verify.refund_confirm_yes' : 'verify.reversal_confirm_yes'),
            cancelLabel: t('verify.accept_back'),
            tone: 'danger',
        });
        if (ok) post(route('payments.refund', payment.id), { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="space-y-5" data-testid="refund-panel">
            <p className="text-base text-stone-700 dark:text-stone-300">{t('verify.refund_intro')}</p>
            <ChoiceGroup label={t('verify.refund_kind')} value={data.kind} onChange={(v) => setData('kind', v)} options={kinds} />
            <Field id="refund_amount" label={t('verify.refund_amount', { max: money(refund.refundable) })} error={tooMuch ? t('verify.refund_too_much', { max: money(refund.refundable) }) : errors.amount}>
                <Input type="number" inputMode="decimal" step="0.01" value={data.amount} onChange={(e) => setData('amount', e.target.value)} />
            </Field>
            <Field id="refund_reason" label={t('verify.refund_reason')} error={errors.reason}>
                <TextArea rows={3} maxLength={500} value={data.reason} onChange={(e) => setData('reason', e.target.value)} />
            </Field>
            <Field id="refund_reference" label={t('verify.refund_reference')} optional error={errors.reference}>
                <Input value={data.reference} onChange={(e) => setData('reference', e.target.value)} />
            </Field>
            <Button type="submit" variant="danger" busy={processing} disabled={data.reason.trim().length < 3 || Number(data.amount) <= 0 || tooMuch}>{t(data.kind === 'refund' ? 'verify.refund_button' : 'verify.reversal_button')}</Button>
            {dialog}
        </form>
    );
}

export default function Review({ payment, student, invoice, blocked, duplicates, next, waiting, refund, refunds, doneUrl }) {
    const { t } = useTranslation();
    const [ask, confirmDialog] = useConfirm();
    const [rejecting, setRejecting] = useState(false);
    const [busy, setBusy] = useState(false);
    const pending = payment.status === 'pending';
    const verified = payment.status === 'verified';
    const rejected = payment.status === 'rejected';
    const MethodIcon = METHOD_ICON[payment.method] ?? METHOD_ICON.other;
    const method = t(`verify.method_${payment.method}`);
    const title = pending ? t('verify.review_title') : verified ? t('verify.received_title') : t('verify.rejected_title');

    useEffect(() => { setRejecting(false); }, [payment.status]);

    const accept = async () => {
        const left = invoice.balance_after;
        const ok = await ask({
            title: t('verify.accept_title'),
            body: (
                <>
                    <p className="text-lg font-semibold text-stone-900 dark:text-stone-100">{student.name} · {money(payment.amount)} ETB · {method}</p>
                    <ul className="list-inside list-disc space-y-2">
                        <li>{t('verify.accept_line_record', { amount: money(payment.amount), invoice: invoice.number })}</li>
                        <li>{left === 0 ? t('verify.accept_line_paid') : t('verify.accept_line_left', { left: money(left) })}</li>
                        <li>{t('verify.accept_line_receipt')}</li>
                        <li>{t('verify.accept_line_final')}</li>
                    </ul>
                </>
            ),
            confirmLabel: t('verify.accept_yes'),
            cancelLabel: t('verify.accept_back'),
            tone: 'info',
        });
        if (!ok) return;
        setBusy(true);
        router.post(route('payments.verify', payment.id), {}, { onFinish: () => setBusy(false) });
    };

    const nextButton = next && (
        <Button as={Link} href={next.review_url} fullWidth data-testid="verify-next">
            {t('verify.next_button')} — {next.student}, {money(next.amount)} ETB
        </Button>
    );

    return (
        <AuthenticatedLayout header={<h2 className="truncate text-lg font-semibold text-stone-800 dark:text-stone-200">{title}</h2>}>
            <Page title={title} description={pending ? t('verify.review_desc', { count: waiting }) : undefined} width="wide" back={<BackLink href={route('payments.pending')}>{t('verify.back_to_list')}</BackLink>}>
                <FlashMessages errorKeys={['payment', 'amount', 'reason']} skip={pending ? [] : ['success']} />

                {verified && payment.channel === 'counter' && (
                    <SuccessPanel
                        title={t('rec.recorded_title')}
                        details={
                            <dl className="divide-y divide-emerald-200 rounded-xl bg-white/70 px-4 dark:divide-emerald-800 dark:bg-stone-900/40" data-testid="recorded-details">
                                <Row label={t('rec.rec_student')}>{student.name}{student.class ? ` · ${student.class}` : ''}</Row>
                                <Row label={t('rec.rec_amount')}><span className="text-3xl font-bold" data-testid="recorded-amount">{money2(payment.amount)} ETB</span></Row>
                                <Row label={t('rec.rec_left')}><span className="text-3xl font-bold" data-testid="recorded-left">{money2(invoice.balance)} ETB</span>{invoice.balance === 0 && <span className="ml-2 text-base font-semibold text-emerald-800 dark:text-emerald-300">{t('rec.r_paid_off')}</span>}</Row>
                                <Row label={t('rec.rec_reference')}><span data-testid="recorded-reference">{payment.reference_number ?? t('rec.rec_no_reference')}</span></Row>
                                <Row label={t('verify.receipt_number')}><span className="font-bold" data-testid="receipt-number">{payment.receipt_number}</span></Row>
                                {payment.note && <Row label={t('rec.r_note')}>{payment.note}</Row>}
                            </dl>
                        }
                        actions={
                            <div className="flex w-full flex-col gap-3">
                                {payment.receipt_url && <Button as="a" href={payment.receipt_url} fullWidth icon={PrinterIcon} data-testid="print-receipt">{t('rec.print_confirmation')}</Button>}
                                <Button as={Link} href={doneUrl} variant="secondary" fullWidth data-testid="recorded-done">{t('rec.done')}</Button>
                            </div>
                        }
                    >
                        {t('rec.recorded_line', { payer: payment.paid_by_name })}
                    </SuccessPanel>
                )}

                {verified && payment.channel !== 'counter' && (
                    <SuccessPanel
                        title={t('verify.received_title')}
                        details={
                            <dl className="divide-y divide-emerald-200 rounded-xl bg-white/70 px-4 dark:divide-emerald-800 dark:bg-stone-900/40" data-testid="received-details">
                                <Row label={t('verify.receipt_number')}><span className="text-2xl font-bold" data-testid="receipt-number">{payment.receipt_number}</span></Row>
                                <Row label={t('verify.f_how')}>{method}{payment.reference_number ? ` · ${t('verify.reference')}: ${payment.reference_number}` : ''}</Row>
                                <Row label={t('verify.f_for')}>{invoice.number}{invoice.period_label ? ` · ${invoice.period_label}` : ''}</Row>
                                <Row label={t('verify.invoice_now')}>{invoice.balance === 0 ? t('verify.now_paid') : t('verify.now_left', { left: money(invoice.balance) })}</Row>
                                <Row label={t('verify.verified_by')}>{payment.verified_by} · {payment.verified_at}</Row>
                            </dl>
                        }
                        actions={
                            <div className="flex w-full flex-col gap-3">
                                {payment.receipt_url && <Button as="a" href={payment.receipt_url} fullWidth icon={PrinterIcon} data-testid="print-receipt">{t('verify.print_receipt')}</Button>}
                                {nextButton}
                                {!next && <p className="text-base text-emerald-950 dark:text-emerald-100">{t('verify.none_waiting')}</p>}
                                <Button as={Link} href={route('payments.pending')} variant="secondary" fullWidth>{t('verify.back_to_list')}</Button>
                            </div>
                        }
                    >
                        {t('verify.received_body', { amount: money(payment.amount), parent: payment.channel === 'counter' ? payment.paid_by_name : payment.submitted_by, student: student.name })}
                    </SuccessPanel>
                )}

                {rejected && (
                    <Alert tone="danger" title={t('verify.rejected_title')} action={null}>
                        <p className="text-base">{t('verify.rejected_reason')} <strong data-testid="rejection-reason">{payment.rejection_reason}</strong></p>
                        <p className="mt-1 text-base">{t('verify.rejected_told')}</p>
                        <div className="mt-4 flex flex-col gap-3 sm:flex-row">
                            {next && <Button as={Link} href={next.review_url}>{t('verify.next_button')}</Button>}
                            <Button as={Link} href={route('payments.pending')} variant="secondary">{t('verify.back_to_list')}</Button>
                        </div>
                    </Alert>
                )}

                <div className="grid gap-6 lg:grid-cols-2">
                    <Card className="lg:col-start-1 lg:row-start-1" data-testid="summary">
                        <div className="mb-2 flex flex-wrap items-center gap-3">
                            <StatusBadge status={payment.status} />
                            {pending && <span className="text-base text-stone-700 dark:text-stone-300">{t('verify.sent_ago', { when: payment.submitted_ago })}</span>}
                        </div>
                        <p className="text-5xl font-bold text-stone-900 dark:text-stone-100" data-testid="amount">{money(payment.amount)} <span className="text-2xl font-semibold text-stone-600 dark:text-stone-400">ETB</span></p>
                        <dl className="mt-3 divide-y divide-stone-200 dark:divide-stone-700">
                            <Row label={t('verify.f_who')}>
                                {payment.channel === 'counter' && <span className="block text-base font-semibold" data-testid="counter-who">{t('rec.paid_at_school', { payer: payment.paid_by_name, who: payment.submitted_by })}</span>}
                                <span className={`font-semibold ${payment.channel === 'counter' ? 'hidden' : ''}`}>{payment.submitted_by}{payment.submitted_by_relationship ? ` (${payment.submitted_by_relationship})` : ''}</span>
                                <span className="block text-base text-stone-700 dark:text-stone-300">{t('verify.paying_for')} <strong>{student.name}</strong>{student.class ? ` · ${student.class}` : ''} · {student.student_id_number}</span>
                            </Row>
                            <Row label={t('verify.f_for')}>
                                <span className="font-semibold">{invoice.number}{invoice.period_label ? ` · ${invoice.period_label}` : ''}</span>
                                <ul className="mt-1 space-y-0.5 text-base text-stone-700 dark:text-stone-300">
                                    {invoice.items.map((i, k) => <li key={k} className="flex justify-between gap-3"><span>{i.description}</span><span>{money(i.amount)}</span></li>)}
                                    {invoice.discount > 0 && <li className="flex justify-between gap-3"><span>{t('verify.discount')}</span><span>−{money(invoice.discount)}</span></li>}
                                </ul>
                                <span className="mt-2 grid grid-cols-3 gap-2 text-base">
                                    <span><span className="block text-sm text-stone-600 dark:text-stone-400">{t('verify.inv_total')}</span><strong>{money(invoice.total)}</strong></span>
                                    <span><span className="block text-sm text-stone-600 dark:text-stone-400">{t('verify.inv_paid')}</span><strong>{money(invoice.collected)}</strong></span>
                                    <span><span className="block text-sm text-stone-600 dark:text-stone-400">{t('verify.inv_owed')}</span><strong data-testid="owed-now">{money(invoice.balance)}</strong></span>
                                </span>
                            </Row>
                            <Row label={t('verify.f_how')}>
                                <span className="inline-flex items-center gap-2 font-semibold"><MethodIcon className="h-6 w-6" aria-hidden="true" />{method}</span>
                                <span className="block text-base text-stone-700 dark:text-stone-300">{payment.reference_number ? <>{t('verify.reference')}: <strong>{payment.reference_number}</strong></> : t('verify.no_reference')}</span>
                                <span className="block text-sm text-stone-600 dark:text-stone-400">{payment.submitted_at}</span>
                            </Row>
                        </dl>
                        {pending && (
                            <div className="mt-2 rounded-xl bg-sky-50 p-4 text-base text-sky-950 dark:bg-sky-900/25 dark:text-sky-50" data-testid="after">
                                {invoice.balance_after === 0 ? t('verify.after_paid') : t('verify.after_left', { left: money(invoice.balance_after) })}
                            </div>
                        )}
                    </Card>

                    <Card className="lg:col-start-2 lg:row-span-2 lg:row-start-1" data-testid="proof">
                        <h2 className="mb-3 text-xl font-semibold text-stone-900 dark:text-stone-100">{t('verify.proof_title')}</h2>
                        <ProofViewer url={payment.proof_url} kind={payment.proof_kind} />
                    </Card>

                    <div className="space-y-4 lg:col-start-1 lg:row-start-2">
                        {blocked && (
                            <Alert tone="warning" title={t(blocked === 'waived' ? 'verify.blocked_waived_title' : 'verify.blocked_over_title')} >
                                <span data-testid="blocked">{blocked === 'waived' ? t('verify.blocked_waived_body') : t('verify.blocked_over_body', { balance: money(invoice.balance) })}</span>
                            </Alert>
                        )}
                        {pending && duplicates.length > 0 && (
                            <Alert tone="warning" title={t('verify.dup_title')}>
                                <p>{t('verify.dup_body')}</p>
                                <ul className="mt-2 space-y-2" data-testid="duplicates">
                                    {duplicates.map((d) => (
                                        <li key={d.id}>
                                            <Link href={d.review_url} className="font-semibold text-brand-800 underline dark:text-brand-300">{d.student} · {d.invoice_number} · {money(d.amount)} ETB</Link>
                                            <span className="block text-sm">{t(`verify.dup_${d.why}`)} · <StatusBadge status={d.status} /> · {d.when}</span>
                                        </li>
                                    ))}
                                </ul>
                            </Alert>
                        )}

                        {pending && !rejecting && (
                            <div className="fixed inset-x-0 bottom-0 z-20 border-t-2 border-stone-200 bg-white/95 px-4 py-3 backdrop-blur dark:border-stone-700 dark:bg-stone-900/95 lg:static lg:z-auto lg:border-0 lg:bg-transparent lg:p-0 lg:backdrop-blur-none" data-testid="actions">
                                <div className="flex flex-col gap-3">
                                    <Button variant="success" fullWidth className="!min-h-[64px] text-xl" icon={CheckCircleIcon} busy={busy} disabled={Boolean(blocked)} onClick={accept}>{t('verify.accept_button')}</Button>
                                    <Button variant="danger" fullWidth className="!min-h-[64px] text-xl" icon={XCircleIcon} onClick={() => setRejecting(true)}>{t('verify.reject_open')}</Button>
                                </div>
                                {blocked && <p className="mt-2 text-sm font-medium text-stone-700 dark:text-stone-300" role="status">{t('verify.accept_why_off')}</p>}
                            </div>
                        )}
                        {pending && rejecting && <RejectPanel payment={payment} onCancel={() => setRejecting(false)} />}

                        {invoice.id && <Button as={Link} href={route('invoices.show', invoice.id)} variant="quiet">{t('verify.open_invoice')}</Button>}
                    </div>
                </div>

                {refunds.length > 0 && (
                    <Card data-testid="refund-list">
                        <h2 className="mb-2 text-xl font-semibold text-stone-900 dark:text-stone-100">{t('verify.refunds_title')}</h2>
                        <ul className="divide-y divide-stone-200 dark:divide-stone-700">
                            {refunds.map((r) => (
                                <li key={r.id} className="py-3 text-base text-stone-900 dark:text-stone-100">
                                    <strong>{t(r.kind === 'refund' ? 'verify.kind_refund' : 'verify.kind_reversal')} {money(r.amount)} ETB</strong> — {r.reason}
                                    <span className="block text-sm text-stone-600 dark:text-stone-400">{r.by} · {r.at}{r.reference ? ` · ${t('verify.reference')}: ${r.reference}` : ''}</span>
                                </li>
                            ))}
                        </ul>
                    </Card>
                )}

                {pending && !rejecting && <div className="h-44 lg:hidden" aria-hidden="true" />}

                {verified && refund && refund.refundable > 0 && (
                    <Disclosure tone="danger" title={t('verify.refund_title')} description={t('verify.refund_desc', { max: money(refund.refundable) })}>
                        <RefundPanel payment={payment} refund={refund} />
                    </Disclosure>
                )}
            </Page>
            {confirmDialog}
        </AuthenticatedLayout>
    );
}
