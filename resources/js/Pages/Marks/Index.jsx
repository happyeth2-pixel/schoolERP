import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';

const STATUS_STYLE = {
    not_started: 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400',
    draft: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
    submitted: 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
    approved: 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300',
    published: 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300',
};

export default function Index({ rows }) {
    return (
        <AuthenticatedLayout
            header={<h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">Enter Marks</h2>}
        >
            <Head title="Enter Marks" />

            <div className="py-8">
                <div className="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
                    {rows.length === 0 && (
                        <p className="text-sm text-gray-400">
                            No exams or teaching assignments found for the current academic year yet.
                        </p>
                    )}
                    <div className="overflow-hidden rounded-lg bg-white shadow-sm dark:bg-gray-800">
                        <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead className="bg-gray-50 dark:bg-gray-900/40">
                                <tr>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Section</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Subject</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Exam</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Status</th>
                                    <th className="px-4 py-3" />
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
                                {rows.map((r, i) => (
                                    <tr key={i}>
                                        <td className="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">{r.section_label}</td>
                                        <td className="px-4 py-3 text-sm text-gray-500">{r.subject_name}</td>
                                        <td className="px-4 py-3 text-sm text-gray-500">{r.exam_name}</td>
                                        <td className="px-4 py-3 text-sm">
                                            <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${STATUS_STYLE[r.status]}`}>
                                                {r.status.replace('_', ' ')}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3 text-right text-sm">
                                            <Link
                                                href={route('marks.show', [r.exam_id, r.section_id, r.subject_id])}
                                                className="text-indigo-600 hover:underline dark:text-indigo-400"
                                            >
                                                {r.status === 'not_started' ? 'Enter' : 'Open'}
                                            </Link>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
