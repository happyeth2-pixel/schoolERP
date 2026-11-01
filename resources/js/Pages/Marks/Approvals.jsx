import { BackLink } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

function ReopenForm({ batch, onDone }) {
    const [reason, setReason] = useState('');

    const submit = (e) => {
        e.preventDefault();
        router.post(route('mark-batches.reopen', batch.id), { reason }, { onSuccess: onDone });
    };

    return (
        <form onSubmit={submit} className="mt-2 flex items-center gap-2">
            <input
                className="flex-1 rounded-md border-stone-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500 dark:border-stone-700 dark:bg-stone-900 dark:text-stone-100"
                placeholder="Reason for reopening (required, logged in audit trail)"
                value={reason}
                onChange={(e) => setReason(e.target.value)}
            />
            <button type="submit" disabled={!reason} className="rounded-md bg-red-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-red-700 disabled:opacity-50">
                Confirm Reopen
            </button>
        </form>
    );
}

export default function Approvals({ batches, canApprove, canPublish }) {
    const { t } = useTranslation();
    const { auth, flash, errors } = usePage().props;
    const [reopeningId, setReopeningId] = useState(null);

    const act = (route_, id) => router.post(route(route_, id));
    const sendBack = (id) => {
        if (confirm(t('confirm.marks_return_batch'))) {
            act('mark-batches.send-back', id);
        }
    };

    return (
        <AuthenticatedLayout
            header={<h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">Result Approvals</h2>}
        >
            <Head title="Result Approvals" />

            <div className="py-8">
                <div className="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8">
                    <div className="mb-4"><BackLink big={auth.role === 'teacher'} href={route('marks.index')}>{t('common.back')}</BackLink></div>
                    {flash?.success && (
                        <div className="mb-4 rounded-md bg-green-50 px-4 py-3 text-sm text-green-700 dark:bg-green-900/30 dark:text-green-300">
                            {flash.success}
                        </div>
                    )}

                    {errors?.publish && (
                        <div className="mb-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-300">{errors.publish}</div>
                    )}

                    <div className="space-y-3">
                        {batches.map((b) => (
                            <div key={b.id} className="rounded-lg bg-white p-4 shadow-sm dark:bg-stone-800">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <div>
                                        <p className="text-sm font-medium text-stone-900 dark:text-stone-100">
                                            {b.section} — {b.subject} — {b.exam}
                                        </p>
                                        <p className="text-xs text-stone-400">Teacher: {b.teacher}</p>
                                    </div>
                                    <div className="flex items-center gap-3">
                                        <span className="rounded-full bg-stone-100 px-2 py-0.5 text-xs font-medium text-stone-600 dark:bg-stone-700 dark:text-stone-300">
                                            {b.status}
                                        </span>
                                        <Link href={route('mark-batches.view', b.id)} className="text-sm text-brand-600 hover:underline dark:text-brand-400">{t('marks.view_link')}</Link>
                                        {canApprove && b.status === 'submitted' && (
                                            <>
                                                <button className="text-sm text-green-600 hover:underline dark:text-green-400" onClick={() => act('mark-batches.approve', b.id)}>Approve</button>
                                                <button className="text-sm text-red-600 hover:underline dark:text-red-400" onClick={() => sendBack(b.id)}>Send Back</button>
                                            </>
                                        )}
                                        {canApprove && b.status === 'approved' && (
                                            <button className="text-sm text-red-600 hover:underline dark:text-red-400" onClick={() => sendBack(b.id)}>Send Back</button>
                                        )}
                                        {canPublish && b.status === 'approved' && (
                                            b.weights_ok ? (
                                                <button className="text-sm text-brand-600 hover:underline dark:text-brand-400" onClick={() => act('mark-batches.publish', b.id)}>Publish</button>
                                            ) : (
                                                <span className="text-xs text-amber-600 dark:text-amber-400" title="Fix the term's exam weights under Exams">Can't publish: term weights total {b.weights_total}%, not 100%</span>
                                            )
                                        )}
                                        {canPublish && b.status === 'published' && (
                                            <button className="text-sm text-red-600 hover:underline dark:text-red-400" onClick={() => setReopeningId(reopeningId === b.id ? null : b.id)}>
                                                Reopen for Correction
                                            </button>
                                        )}
                                    </div>
                                </div>
                                {reopeningId === b.id && (
                                    <ReopenForm batch={b} onDone={() => setReopeningId(null)} />
                                )}
                            </div>
                        ))}
                        {batches.length === 0 && (
                            <p className="text-center text-sm text-stone-400">No result batches yet.</p>
                        )}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
