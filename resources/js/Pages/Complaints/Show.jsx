import PrimaryButton from '@/Components/PrimaryButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, usePage } from '@inertiajs/react';

const STATUS_STYLE = {
    open: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
    in_progress: 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
    resolved: 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300',
};

function ManageForm({ complaint }) {
    const { data, setData, post, processing } = useForm({
        status: complaint.status,
        resolution_note: complaint.resolution_note ?? '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('complaints.status', complaint.id));
    };

    return (
        <form onSubmit={submit} className="space-y-4 rounded-lg bg-white p-6 shadow-sm dark:bg-gray-800">
            <h3 className="text-sm font-semibold uppercase tracking-wide text-gray-400">Manage</h3>
            <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Status</label>
                <select
                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
                    value={data.status}
                    onChange={(e) => setData('status', e.target.value)}
                >
                    <option value="open">Open</option>
                    <option value="in_progress">In Progress</option>
                    <option value="resolved">Resolved</option>
                </select>
            </div>
            <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Resolution note (shown to the submitter)</label>
                <textarea
                    rows={4}
                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
                    value={data.resolution_note}
                    onChange={(e) => setData('resolution_note', e.target.value)}
                />
            </div>
            <PrimaryButton disabled={processing}>Save</PrimaryButton>
        </form>
    );
}

export default function Show({ complaint, canManage }) {
    const { flash } = usePage().props;

    return (
        <AuthenticatedLayout
            header={<h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">Complaint</h2>}
        >
            <Head title={complaint.subject} />

            <div className="py-8">
                <div className="mx-auto max-w-2xl space-y-4 px-4 sm:px-6 lg:px-8">
                    {flash?.success && (
                        <div className="rounded-md bg-green-50 px-4 py-3 text-sm text-green-700 dark:bg-green-900/30 dark:text-green-300">
                            {flash.success}
                        </div>
                    )}

                    <div className="rounded-lg bg-white p-6 shadow-sm dark:bg-gray-800">
                        <div className="mb-2 flex items-center justify-between">
                            <h3 className="text-lg font-semibold text-gray-900 dark:text-gray-100">{complaint.subject}</h3>
                            <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${STATUS_STYLE[complaint.status]}`}>
                                {complaint.status.replace('_', ' ')}
                            </span>
                        </div>
                        <p className="text-sm text-gray-500 dark:text-gray-400">Submitted {complaint.created_at}</p>

                        <div className="mt-4 grid gap-3 rounded-md border border-gray-200 p-4 text-sm dark:border-gray-700 sm:grid-cols-2">
                            {canManage && (
                                <div>
                                    <p className="text-xs font-semibold uppercase tracking-wide text-gray-400">From</p>
                                    <p className="font-medium text-gray-900 dark:text-gray-100">{complaint.submitted_by}</p>
                                    {complaint.submitted_by_email && <p className="text-gray-500">{complaint.submitted_by_email}</p>}
                                    {complaint.submitted_by_phone && <p className="text-gray-500">{complaint.submitted_by_phone}</p>}
                                </div>
                            )}
                            <div>
                                <p className="text-xs font-semibold uppercase tracking-wide text-gray-400">About</p>
                                {complaint.student ? (
                                    <p className="font-medium text-gray-900 dark:text-gray-100">{complaint.student.name} <span className="font-normal text-gray-500">— {complaint.student.section}</span></p>
                                ) : (
                                    <p className="text-gray-500">General — not tied to a specific child</p>
                                )}
                                {canManage && complaint.siblings?.length > 0 && (
                                    <p className="mt-1 text-gray-500">
                                        Also has: {complaint.siblings.map((s) => `${s.name} (${s.section})`).join(', ')}
                                    </p>
                                )}
                            </div>
                        </div>

                        <p className="mt-4 whitespace-pre-wrap text-sm text-gray-700 dark:text-gray-300">{complaint.body}</p>

                        {complaint.resolution_note && (
                            <div className="mt-4 rounded-md bg-gray-50 p-4 text-sm dark:bg-gray-900/40">
                                <p className="font-medium text-gray-700 dark:text-gray-300">Response{complaint.resolved_by ? ` from ${complaint.resolved_by}` : ''}:</p>
                                <p className="mt-1 whitespace-pre-wrap text-gray-600 dark:text-gray-400">{complaint.resolution_note}</p>
                            </div>
                        )}
                    </div>

                    {canManage && <ManageForm complaint={complaint} />}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
