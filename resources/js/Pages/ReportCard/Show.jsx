import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';

export default function Show({ student, section, academic_year, subjects, overall_average, overall_letter, rank, class_size }) {
    return (
        <AuthenticatedLayout
            header={<h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">Report Card — {student.name}</h2>}
        >
            <Head title={`Report Card — ${student.name}`} />

            <div className="py-8">
                <div className="mx-auto max-w-2xl px-4 sm:px-6 lg:px-8">
                    <div className="mb-4 flex justify-end">
                        <a
                            href={route('report-card.pdf', student.id)}
                            className="text-sm text-indigo-600 hover:underline dark:text-indigo-400"
                        >
                            Download PDF
                        </a>
                    </div>

                    <div className="rounded-lg bg-white p-6 shadow-sm dark:bg-gray-800">
                        <p className="text-sm text-gray-500">{academic_year}</p>
                        <p className="text-lg font-medium text-gray-900 dark:text-gray-100">{student.name}</p>
                        <p className="text-sm text-gray-400">{student.student_id_number} · {section ?? 'Unassigned'}</p>

                        <table className="mt-4 min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead>
                                <tr>
                                    <th className="py-2 text-left text-xs font-medium uppercase text-gray-500">Subject</th>
                                    <th className="py-2 text-left text-xs font-medium uppercase text-gray-500">Average</th>
                                    <th className="py-2 text-left text-xs font-medium uppercase text-gray-500">Grade</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100 dark:divide-gray-700">
                                {subjects.map((s) => (
                                    <tr key={s.subject}>
                                        <td className="py-2 text-sm text-gray-900 dark:text-gray-100">{s.subject}</td>
                                        <td className="py-2 text-sm text-gray-500">{s.average}</td>
                                        <td className="py-2 text-sm font-medium text-gray-900 dark:text-gray-100">{s.letter ?? '—'}</td>
                                    </tr>
                                ))}
                                {subjects.length === 0 && (
                                    <tr><td colSpan={3} className="py-4 text-center text-sm text-gray-400">No published results yet.</td></tr>
                                )}
                            </tbody>
                        </table>

                        <div className="mt-4 grid grid-cols-3 gap-4 border-t border-gray-100 pt-4 dark:border-gray-700">
                            <div>
                                <p className="text-xs text-gray-400">Overall Average</p>
                                <p className="text-lg font-semibold text-gray-900 dark:text-gray-100">{overall_average ?? '—'}</p>
                            </div>
                            <div>
                                <p className="text-xs text-gray-400">Overall Grade</p>
                                <p className="text-lg font-semibold text-gray-900 dark:text-gray-100">{overall_letter ?? '—'}</p>
                            </div>
                            <div>
                                <p className="text-xs text-gray-400">Class Rank</p>
                                <p className="text-lg font-semibold text-gray-900 dark:text-gray-100">{rank ? `${rank} / ${class_size}` : '—'}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
