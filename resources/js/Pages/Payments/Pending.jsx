import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

function RejectForm({ payment, onDone }) {
    const [reason, setReason] = useState('');

    const submit = (e) => {
        e.preventDefault();
        router.post(route('payments.reject', payment.id), { rejection_reason: reason }, { onSuccess: onDone });
    };

    return (
        <form onSubmit={submit} className="mt-2 flex items-center gap-2">
            <input
                className="flex-1 rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
                placeholder="Reason for rejection"
                value={reason}
                onChange={(e) => setReason(e.target.value)}
            />
            <button type="submit" disabled={!reason} className="rounded-md bg-red-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-red-700 disabled:opacity-50">
                Confirm Reject
            </button>
        </form>
    );
}

export default function Pending({ payments }) {
    const { flash } = usePage().props;
    const [rejectingId, setRejectingId] = useState(null);

    const verify = (id) => {
        if (confirm('Mark this payment as verified? A receipt will be generated.')) {
            router.post(route('payments.verify', id));
        }
    };

    return (
        <AuthenticatedLayout
            header={<h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">Payment Verification</h2>}
        >
            <Head title="Payment Verification" />

            <div className="py-8">
                <div className="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
                    {flash?.success && (
                        <div className="mb-4 rounded-md bg-green-50 px-4 py-3 text-sm text-green-700 dark:bg-green-900/30 dark:text-green-300">
                            {flash.success}
                        </div>
                    )}

                    <div className="space-y-3">
                        {payments.map((p) => (
                            <div key={p.id} className="rounded-lg bg-white p-4 shadow-sm dark:bg-gray-800">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <div>
                                        <p className="text-sm font-medium text-gray-900 dark:text-gray-100">
                                            {p.student} — {Number(p.amount).toLocaleString()} ETB
                                        </p>
                                        <p className="text-xs text-gray-400">
                                            {p.invoice_number} · {p.method.replace('_', ' ')} · ref: {p.reference_number ?? 'none'} · submitted by {p.submitted_by} {p.submitted_at}
                                        </p>
                                    </div>
                                    <div className="flex items-center gap-3 text-sm">
                                        <a href={route('payments.proof', p.id)} target="_blank" className="text-indigo-600 hover:underline dark:text-indigo-400">View Proof</a>
                                        <Link href={route('invoices.show', p.invoice_id)} className="text-indigo-600 hover:underline dark:text-indigo-400">Invoice</Link>
                                        <button className="text-green-600 hover:underline dark:text-green-400" onClick={() => verify(p.id)}>Verify</button>
                                        <button className="text-red-600 hover:underline dark:text-red-400" onClick={() => setRejectingId(rejectingId === p.id ? null : p.id)}>Reject</button>
                                    </div>
                                </div>
                                {rejectingId === p.id && <RejectForm payment={p} onDone={() => setRejectingId(null)} />}
                            </div>
                        ))}
                        {payments.length === 0 && (
                            <p className="text-center text-sm text-gray-400">No payments waiting for verification.</p>
                        )}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
