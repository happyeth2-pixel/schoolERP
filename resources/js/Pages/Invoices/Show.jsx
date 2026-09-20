import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

function money(n) {
    return Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function DiscountForm({ invoice, onDone }) {
    const { data, setData, patch, processing } = useForm({
        discount_amount: invoice.discount_amount,
        discount_reason: invoice.discount_reason ?? '',
    });

    const submit = (e) => {
        e.preventDefault();
        patch(route('invoices.discount', invoice.id), { onSuccess: onDone });
    };

    return (
        <form onSubmit={submit} className="mt-2 flex flex-wrap items-end gap-2">
            <div>
                <InputLabel htmlFor="discount_amount" value="Discount (ETB)" />
                <TextInput id="discount_amount" type="number" step="0.01" className="mt-1 w-32" value={data.discount_amount} onChange={(e) => setData('discount_amount', e.target.value)} />
            </div>
            <div className="flex-1">
                <InputLabel htmlFor="discount_reason" value="Reason" />
                <TextInput id="discount_reason" className="mt-1 block w-full" value={data.discount_reason} onChange={(e) => setData('discount_reason', e.target.value)} />
            </div>
            <PrimaryButton disabled={processing}>Save</PrimaryButton>
        </form>
    );
}

function PaymentForm({ invoiceId, onDone }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        amount: '', method: 'bank_transfer', reference_number: '', proof: null,
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('payments.store', invoiceId), {
            forceFormData: true,
            onSuccess: () => { reset(); onDone(); },
        });
    };

    return (
        <form onSubmit={submit} className="space-y-3 rounded-lg border border-gray-200 p-4 dark:border-gray-700">
            <h3 className="text-sm font-semibold text-gray-700 dark:text-gray-300">Submit Payment Proof</h3>
            <div className="grid grid-cols-2 gap-3">
                <div>
                    <InputLabel htmlFor="amount" value="Amount Paid (ETB)" />
                    <TextInput id="amount" type="number" step="0.01" className="mt-1 block w-full" value={data.amount} onChange={(e) => setData('amount', e.target.value)} />
                    <InputError message={errors.amount} className="mt-1" />
                </div>
                <div>
                    <InputLabel htmlFor="method" value="Method" />
                    <select
                        id="method"
                        className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
                        value={data.method}
                        onChange={(e) => setData('method', e.target.value)}
                    >
                        <option value="bank_transfer">Bank Transfer</option>
                        <option value="mobile_money">Mobile Money (Telebirr, etc.)</option>
                        <option value="cash">Cash</option>
                        <option value="other">Other</option>
                    </select>
                </div>
            </div>
            <div>
                <InputLabel htmlFor="reference_number" value="Reference / Transaction ID" />
                <TextInput id="reference_number" className="mt-1 block w-full" value={data.reference_number} onChange={(e) => setData('reference_number', e.target.value)} />
            </div>
            <div>
                <InputLabel htmlFor="proof" value="Payment Screenshot (JPG, PNG, or PDF)" />
                <input
                    id="proof"
                    type="file"
                    accept=".jpg,.jpeg,.png,.pdf"
                    className="mt-1 block w-full text-sm text-gray-600 dark:text-gray-300"
                    onChange={(e) => setData('proof', e.target.files[0])}
                />
                <InputError message={errors.proof} className="mt-1" />
            </div>
            <PrimaryButton disabled={processing}>Submit for Verification</PrimaryButton>
        </form>
    );
}

const PAYMENT_STATUS_STYLE = {
    pending: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
    verified: 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300',
    rejected: 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
};

function PaymentAccountInfo({ account }) {
    const rows = [
        ['Bank', account.bank_name],
        ['Account Number', account.bank_account_number],
        ['Account Name', account.bank_account_name],
        ['Mobile Money', account.mobile_money_number],
    ].filter(([, value]) => value);

    if (rows.length === 0) {
        return null;
    }

    return (
        <div className="rounded-md border border-indigo-200 bg-indigo-50 p-3 text-sm dark:border-indigo-800 dark:bg-indigo-900/30">
            <p className="mb-1 font-medium text-indigo-800 dark:text-indigo-300">Send payment to:</p>
            {rows.map(([label, value]) => (
                <p key={label} className="text-indigo-700 dark:text-indigo-300">
                    <span className="text-indigo-500 dark:text-indigo-400">{label}:</span> {value}
                </p>
            ))}
        </div>
    );
}

export default function Show({ invoice, canManage, paymentAccount }) {
    const { flash, auth } = usePage().props;
    const [editingDiscount, setEditingDiscount] = useState(false);
    const isParent = auth.role === 'parent';

    const toggleWaive = () => {
        if (confirm(invoice.is_waived ? 'Remove waiver?' : 'Waive this invoice?')) {
            router.post(route('invoices.toggle-waive', invoice.id));
        }
    };

    return (
        <AuthenticatedLayout
            header={<h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">Invoice {invoice.invoice_number}</h2>}
        >
            <Head title={`Invoice ${invoice.invoice_number}`} />

            <div className="py-8">
                <div className="mx-auto max-w-2xl space-y-6 px-4 sm:px-6 lg:px-8">
                    {flash?.success && (
                        <div className="rounded-md bg-green-50 px-4 py-3 text-sm text-green-700 dark:bg-green-900/30 dark:text-green-300">
                            {flash.success}
                        </div>
                    )}

                    <div className="rounded-lg bg-white p-6 shadow-sm dark:bg-gray-800">
                        <p className="text-sm text-gray-500">{invoice.student.name} · {invoice.period}</p>

                        <table className="mt-4 min-w-full">
                            <tbody>
                                {invoice.items.map((item) => (
                                    <tr key={item.id}>
                                        <td className="py-1 text-sm text-gray-700 dark:text-gray-300">{item.description}</td>
                                        <td className="py-1 text-right text-sm text-gray-500">{money(item.amount)}</td>
                                    </tr>
                                ))}
                                <tr className="border-t border-gray-100 dark:border-gray-700">
                                    <td className="py-1 text-sm text-gray-500">Subtotal</td>
                                    <td className="py-1 text-right text-sm text-gray-500">{money(invoice.subtotal)}</td>
                                </tr>
                                {invoice.discount_amount > 0 && (
                                    <tr>
                                        <td className="py-1 text-sm text-gray-500">Discount {invoice.discount_reason ? `(${invoice.discount_reason})` : ''}</td>
                                        <td className="py-1 text-right text-sm text-gray-500">-{money(invoice.discount_amount)}</td>
                                    </tr>
                                )}
                                <tr className="font-medium">
                                    <td className="py-1 text-sm text-gray-900 dark:text-gray-100">Total</td>
                                    <td className="py-1 text-right text-sm text-gray-900 dark:text-gray-100">{money(invoice.total)}</td>
                                </tr>
                                <tr>
                                    <td className="py-1 text-sm text-gray-500">Paid</td>
                                    <td className="py-1 text-right text-sm text-gray-500">{money(invoice.paid)}</td>
                                </tr>
                                <tr className="font-medium">
                                    <td className="py-1 text-sm text-gray-900 dark:text-gray-100">Balance</td>
                                    <td className="py-1 text-right text-sm text-gray-900 dark:text-gray-100">{money(invoice.balance)}</td>
                                </tr>
                            </tbody>
                        </table>

                        {canManage && (
                            <div className="mt-4 border-t border-gray-100 pt-4 dark:border-gray-700">
                                <div className="flex items-center justify-between">
                                    <button className="text-sm text-indigo-600 hover:underline dark:text-indigo-400" onClick={() => setEditingDiscount(!editingDiscount)}>
                                        {editingDiscount ? 'Cancel' : 'Edit Discount'}
                                    </button>
                                    <button className="text-sm text-red-600 hover:underline dark:text-red-400" onClick={toggleWaive}>
                                        {invoice.is_waived ? 'Remove Waiver' : 'Waive Invoice'}
                                    </button>
                                </div>
                                {editingDiscount && <DiscountForm invoice={invoice} onDone={() => setEditingDiscount(false)} />}
                            </div>
                        )}
                    </div>

                    <div className="rounded-lg bg-white p-6 shadow-sm dark:bg-gray-800">
                        <h3 className="mb-3 text-sm font-semibold uppercase tracking-wide text-gray-400">Payments</h3>
                        <div className="space-y-2">
                            {invoice.payments.map((p) => (
                                <div key={p.id} className="flex items-center justify-between rounded-md border border-gray-100 p-3 text-sm dark:border-gray-700">
                                    <div>
                                        <span className="font-medium text-gray-900 dark:text-gray-100">{money(p.amount)} ETB</span>
                                        <span className="ml-2 text-gray-400">{p.method.replace('_', ' ')} · {p.reference_number ?? 'no ref'}</span>
                                        {p.rejection_reason && <p className="text-xs text-red-500">Rejected: {p.rejection_reason}</p>}
                                    </div>
                                    <div className="flex items-center gap-3">
                                        <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${PAYMENT_STATUS_STYLE[p.status]}`}>{p.status}</span>
                                        {p.has_proof && <a href={route('payments.proof', p.id)} target="_blank" className="text-indigo-600 hover:underline dark:text-indigo-400">Proof</a>}
                                        {p.status === 'verified' && <a href={route('payments.receipt', p.id)} className="text-indigo-600 hover:underline dark:text-indigo-400">Receipt</a>}
                                    </div>
                                </div>
                            ))}
                            {invoice.payments.length === 0 && <p className="text-sm text-gray-400">No payments submitted yet.</p>}
                        </div>

                        {isParent && invoice.balance > 0 && (
                            <div className="mt-4 space-y-3">
                                <PaymentAccountInfo account={paymentAccount} />
                                <PaymentForm invoiceId={invoice.id} onDone={() => {}} />
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
