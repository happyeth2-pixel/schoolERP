import { Alert, BackLink, Button, Card, Page, SuccessPanel, usePdfPreview } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PrinterIcon } from '@heroicons/react/24/outline';
import { Link } from '@inertiajs/react';

const money = (n) => Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

// What StudentPaymentController::store() made: one payment (and one receipt) per bill the lump sum
// actually reached. Printing each one separately, rather than inventing a combined receipt format,
// keeps every individual bill's own paper trail exactly the same shape payments.receipt already produces.
export default function BulkReceipt({ student, amount, applied, leftover, payments, deskUrl }) {
    const [openReceipt, receiptModal] = usePdfPreview();

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">Payment Recorded</h2>}>
            <Page title="Payment Recorded" width="default" back={<BackLink href={deskUrl}>Back to {student.name}</BackLink>}>
                <SuccessPanel
                    title="Payment recorded"
                    details={
                        <div className="space-y-4">
                            <ul className="space-y-1 rounded-xl bg-white/70 p-4 text-base text-emerald-950 dark:bg-stone-900/40 dark:text-emerald-50">
                                <li>{money(applied)} ETB applied across {payments.length} bill{payments.length === 1 ? '' : 's'}</li>
                                {leftover > 0 && <li>{money(leftover)} ETB could not be applied (no bill left to cover it)</li>}
                            </ul>
                        </div>
                    }
                    actions={<Button as={Link} href={deskUrl} fullWidth>Back to {student.name}</Button>}
                >
                    {money(amount)} ETB received from {student.name}, written in the school's records.
                </SuccessPanel>

                <Card className="mt-6">
                    <p className="mb-3 text-lg font-semibold text-stone-900 dark:text-stone-100">Receipts</p>
                    <ul className="divide-y divide-stone-200 dark:divide-stone-700">
                        {payments.map((p) => (
                            <li key={p.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
                                <div>
                                    <p className="font-semibold text-stone-900 dark:text-stone-100">{p.period} ({p.invoice_number})</p>
                                    <p className="text-sm text-stone-600 dark:text-stone-400">Receipt {p.receipt_number} · {money(p.amount)} ETB</p>
                                </div>
                                <Button variant="secondary" size="md" icon={PrinterIcon} onClick={() => openReceipt({ url: p.receipt_url, title: `Receipt ${p.receipt_number}`, filename: `receipt-${p.receipt_number}.pdf` })}>Print receipt</Button>
                            </li>
                        ))}
                    </ul>
                </Card>

                {leftover > 0 && (
                    <Alert tone="info" title="Some money was not applied yet" className="mt-6">
                        {money(leftover)} ETB has nowhere to go right now because every current bill is fully covered. Generate the next bill, then record the rest separately.
                    </Alert>
                )}
            </Page>
            {receiptModal}
        </AuthenticatedLayout>
    );
}
