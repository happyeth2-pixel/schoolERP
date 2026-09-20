import PrimaryButton from '@/Components/PrimaryButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';

const STATUS_STYLE = {
    open: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
    in_progress: 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
    resolved: 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300',
};

export default function Index({ complaints, isStaffView, statusFilter }) {
    const { flash } = usePage().props;

    const setFilter = (status) => {
        router.get(route('complaints.index'), status ? { status } : {}, { preserveState: true });
    };

    return (
        <AuthenticatedLayout
            header={<h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">{isStaffView ? 'Complaints' : 'My Complaints'}</h2>}
        >
            <Head title="Complaints" />

            <div className="py-8">
                <div className="mx-auto max-w-4xl space-y-4 px-4 sm:px-6 lg:px-8">
                    {flash?.success && (
                        <div className="rounded-md bg-green-50 px-4 py-3 text-sm text-green-700 dark:bg-green-900/30 dark:text-green-300">
                            {flash.success}
                        </div>
                    )}

                    <div className="flex flex-wrap items-center justify-between gap-3">
                        {isStaffView ? (
                            <div className="flex gap-2 text-sm">
                                {[[null, 'All'], ['open', 'Open'], ['in_progress', 'In Progress'], ['resolved', 'Resolved']].map(([value, label]) => (
                                    <button
                                        key={label}
                                        onClick={() => setFilter(value)}
                                        className={`rounded-full px-3 py-1 ${(statusFilter ?? null) === value ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300'}`}
                                    >
                                        {label}
                                    </button>
                                ))}
                            </div>
                        ) : (
                            <div />
                        )}
                        {!isStaffView && (
                            <Link href={route('complaints.create')}>
                                <PrimaryButton>New Complaint</PrimaryButton>
                            </Link>
                        )}
                    </div>

                    <div className="overflow-hidden rounded-lg bg-white shadow-sm dark:bg-gray-800">
                        <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead className="bg-gray-50 dark:bg-gray-900/40">
                                <tr>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Subject</th>
                                    {isStaffView && <th className="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">From</th>}
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Student</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Status</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Submitted</th>
                                    <th className="px-4 py-3" />
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
                                {complaints.map((c) => (
                                    <tr key={c.id}>
                                        <td className="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">{c.subject}</td>
                                        {isStaffView && <td className="px-4 py-3 text-sm text-gray-500">{c.submitted_by}</td>}
                                        <td className="px-4 py-3 text-sm text-gray-500">{c.student ? `${c.student.name} — ${c.student.section}` : '—'}</td>
                                        <td className="px-4 py-3 text-sm">
                                            <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${STATUS_STYLE[c.status]}`}>
                                                {c.status.replace('_', ' ')}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3 text-sm text-gray-500">{c.created_at}</td>
                                        <td className="px-4 py-3 text-right text-sm">
                                            <Link href={route('complaints.show', c.id)} className="text-indigo-600 hover:underline dark:text-indigo-400">View</Link>
                                        </td>
                                    </tr>
                                ))}
                                {complaints.length === 0 && (
                                    <tr>
                                        <td colSpan={isStaffView ? 6 : 5} className="px-4 py-6 text-center text-sm text-gray-400">
                                            {isStaffView ? 'No complaints in this view.' : 'You have not submitted any complaints.'}
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
