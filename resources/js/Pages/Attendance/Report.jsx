import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';

export default function Report({ date, sections, overall }) {
    const changeDate = (newDate) => {
        router.get(route('attendance-report.index'), { date: newDate });
    };

    return (
        <AuthenticatedLayout
            header={<h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">Attendance Report</h2>}
        >
            <Head title="Attendance Report" />

            <div className="py-8">
                <div className="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8">
                    <div className="mb-4 flex items-center justify-between">
                        <input
                            type="date"
                            value={date}
                            onChange={(e) => changeDate(e.target.value)}
                            className="rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
                        />
                    </div>

                    <div className="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
                        {[
                            ['Students', overall.total_students],
                            ['Marked', overall.total_marked],
                            ['Present', overall.total_present],
                            ['Absent', overall.total_absent],
                        ].map(([label, value]) => (
                            <div key={label} className="rounded-lg bg-white p-4 shadow-sm dark:bg-gray-800">
                                <p className="text-xs text-gray-400">{label}</p>
                                <p className="text-xl font-semibold text-gray-900 dark:text-gray-100">{value}</p>
                            </div>
                        ))}
                    </div>

                    <div className="overflow-hidden rounded-lg bg-white shadow-sm dark:bg-gray-800">
                        <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead className="bg-gray-50 dark:bg-gray-900/40">
                                <tr>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Section</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Branch</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Marked</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Present / Absent / Late</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Rate</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
                                {sections.map((s) => (
                                    <tr key={s.id}>
                                        <td className="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">{s.label}</td>
                                        <td className="px-4 py-3 text-sm text-gray-500">{s.branch}</td>
                                        <td className="px-4 py-3 text-sm text-gray-500">{s.marked} / {s.total}</td>
                                        <td className="px-4 py-3 text-sm text-gray-500">
                                            {s.counts.present} / {s.counts.absent} / {s.counts.late}
                                        </td>
                                        <td className="px-4 py-3 text-sm text-gray-500">
                                            {s.attendance_rate !== null ? `${s.attendance_rate}%` : 'Not marked'}
                                        </td>
                                    </tr>
                                ))}
                                {sections.length === 0 && (
                                    <tr>
                                        <td colSpan={5} className="px-4 py-6 text-center text-sm text-gray-400">No sections for the current academic year.</td>
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
