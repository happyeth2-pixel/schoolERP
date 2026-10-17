import SupplierPaymentDialog from '@/Components/SupplierPaymentDialog';
import SupplierPaymentReversalDialog from '@/Components/SupplierPaymentReversalDialog';
import { BackLink, Button, Card, FlashMessages, Page, SectionHeader } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

const money = (n) => Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

export default function Show({ bill, canManage, canReversePayments, reversalKinds }) {
    const { t } = useTranslation();
    const [recordingPayment, setRecordingPayment] = useState(false);
    const [reversingPayment, setReversingPayment] = useState(null);

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">{bill.bill_number}</h2>}>
            <Page title={bill.bill_number} back={<BackLink href={route('supplier-bills.index')}>{t('supplier_bill.back_to_list')}</BackLink>}>
                <FlashMessages />
                <Card>
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p className="text-lg font-semibold text-gray-900 dark:text-gray-100">{bill.supplier}</p>
                            {bill.purchase_order && <p className="text-sm text-gray-600 dark:text-gray-400">{t('supplier_bill.from_order_x', { number: bill.purchase_order })}</p>}
                            {bill.supplier_reference && <p className="text-sm text-gray-600 dark:text-gray-400">{t('supplier_bill.reference_x', { reference: bill.supplier_reference })}</p>}
                        </div>
                    </div>
                    <p className="mt-3 text-xl font-bold text-gray-900 dark:text-gray-100">{money(bill.total)} ETB</p>
                    <p className="text-sm text-gray-700 dark:text-gray-300">{t('supplier_bill.dated_x', { date: bill.bill_date })}</p>
                    {bill.due_date && <p className="text-sm text-gray-700 dark:text-gray-300">{t('supplier_bill.due_x', { date: bill.due_date })}</p>}
                    <p className="mt-2 text-base text-gray-800 dark:text-gray-200">
                        {bill.balance <= 0 ? t('supplier_bill.fully_paid') : t('supplier_bill.paid_of_total', { paid: money(bill.paid), total: money(bill.total) })}
                    </p>
                    {bill.notes && <p className="mt-3 text-base text-gray-700 dark:text-gray-300">{bill.notes}</p>}
                </Card>

                <Card>
                    <SectionHeader as="h3" title={t('supplier_bill.lines_title')} />
                    <ul className="divide-y divide-gray-200 dark:divide-gray-700" data-testid="bill-items">
                        {bill.items.map((i) => (
                            <li key={i.id} className="py-3" data-testid="bill-item-row">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <span className="text-base font-semibold text-gray-900 dark:text-gray-100">{i.description}</span>
                                    <span className="text-base text-gray-800 dark:text-gray-200">{t('pr.quantity_n', { count: i.quantity })} &middot; {money(i.total)} ETB</span>
                                </div>
                            </li>
                        ))}
                    </ul>
                </Card>

                <Card>
                    <SectionHeader
                        as="h3"
                        title={t('supplier_payment.section_title')}
                        action={canManage && <Button size="sm" onClick={() => setRecordingPayment(true)}>{t('supplier_payment.add_button')}</Button>}
                    />
                    {bill.payments.length === 0 ? (
                        <p className="text-base text-gray-600 dark:text-gray-400">{t('supplier_payment.empty_body')}</p>
                    ) : (
                        <ul className="divide-y divide-gray-200 dark:divide-gray-700" data-testid="sp-list">
                            {bill.payments.map((p) => (
                                <li key={p.id} className="py-3" data-testid="sp-row">
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <span className="text-base font-semibold text-gray-900 dark:text-gray-100">{money(p.amount)} ETB</span>
                                        <div className="flex items-center gap-3">
                                            <span className="text-sm text-gray-600 dark:text-gray-400">{p.paid_date}</span>
                                            {canReversePayments && p.reversible > 0 && (
                                                <button type="button" onClick={() => setReversingPayment(p)} className="text-sm text-amber-700 hover:underline dark:text-amber-400">
                                                    {t('supplier_payment.reverse_link')}
                                                </button>
                                            )}
                                        </div>
                                    </div>
                                    <p className="text-sm text-gray-600 dark:text-gray-400">
                                        {t(`supplier_payment.method_${p.method}`)}{p.reference_number ? ` · ${p.reference_number}` : ''} &middot; {t('supplier_payment.by_x', { name: p.recorded_by })}
                                    </p>
                                    {p.reversals.length > 0 && (
                                        <ul className="mt-2 w-full space-y-1 border-t border-gray-100 pt-2 text-xs text-amber-700 dark:border-gray-700 dark:text-amber-400">
                                            {p.reversals.map((r) => (
                                                <li key={r.id}>
                                                    {r.kind === 'refund' ? t('supplier_payment.reversal_kind_refund') : t('supplier_payment.reversal_kind_reversal')} {money(r.amount)} ETB — {r.reason} ({r.by}, {r.at}{r.reference ? `, ${r.reference}` : ''})
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>
            </Page>

            <SupplierPaymentDialog key={`sp-${bill.balance}`} open={recordingPayment} bill={bill} onClose={() => setRecordingPayment(false)} />
            <SupplierPaymentReversalDialog
                key={`spr-${reversingPayment?.id}-${reversingPayment?.reversible}`}
                open={reversingPayment !== null}
                payment={reversingPayment}
                kinds={reversalKinds}
                onClose={() => setReversingPayment(null)}
            />
        </AuthenticatedLayout>
    );
}
