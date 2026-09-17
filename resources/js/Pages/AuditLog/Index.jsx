import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { ArrowDownTrayIcon } from '@heroicons/react/24/outline';
import { Head, Link } from '@inertiajs/react';

export default function Index({ logs }) {
    return (
        <AuthenticatedLayout
            header={<h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">Audit Log</h2>}
        >
            <Head title="Audit Log" />

            <div className="py-8">
                <div className="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8">
                    <div className="mb-4 flex justify-end">
                        <a
                            href={route('audit-log.export')}
                            className="inline-flex items-center gap-1.5 rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
                        >
                            <ArrowDownTrayIcon className="h-4 w-4" /> Export to Excel
                        </a>
                    </div>

                    <div className="overflow-hidden rounded-lg bg-white shadow-sm dark:bg-gray-800">
                        <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead className="bg-gray-50 dark:bg-gray-900/40">
                                <tr>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">When</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Who</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Action</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Change</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Note</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
                                {logs.data.map((log) => (
                                    <tr key={log.id}>
                                        <td className="px-4 py-3 text-sm text-gray-500">{log.created_at}</td>
                                        <td className="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">{log.actor}</td>
                                        <td className="px-4 py-3 text-sm text-gray-500">{log.action}</td>
                                        <td className="px-4 py-3 text-sm text-gray-500">
                                            {log.before && <span>{JSON.stringify(log.before)} → </span>}
                                            {log.after && <span>{JSON.stringify(log.after)}</span>}
                                        </td>
                                        <td className="px-4 py-3 text-sm text-gray-500">{log.note ?? '—'}</td>
                                    </tr>
                                ))}
                                {logs.data.length === 0 && (
                                    <tr><td colSpan={5} className="px-4 py-6 text-center text-sm text-gray-400">No audit entries yet.</td></tr>
                                )}
                            </tbody>
                        </table>
                    </div>

                    <div className="mt-4 flex justify-center gap-2">
                        {logs.links?.map((link, i) => (
                            link.url ? (
                                <Link
                                    key={i}
                                    href={link.url}
                                    className={`rounded px-3 py-1 text-sm ${link.active ? 'bg-indigo-600 text-white' : 'bg-white text-gray-600 dark:bg-gray-800 dark:text-gray-300'}`}
                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                />
                            ) : null
                        ))}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
