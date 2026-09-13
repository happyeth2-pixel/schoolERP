import PrimaryButton from '@/Components/PrimaryButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

const STATUSES = [
    ['present', 'Present', 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300'],
    ['absent', 'Absent', 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300'],
    ['late', 'Late', 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300'],
    ['excused', 'Excused', 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300'],
    ['sick', 'Sick', 'bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-300'],
];

export default function Take({ section, date, roster }) {
    const { flash } = usePage().props;
    const [marks, setMarks] = useState(() =>
        Object.fromEntries(roster.map((r) => [r.enrollment_id, r.status ?? 'present']))
    );
    const [saving, setSaving] = useState(false);

    const changeDate = (newDate) => {
        router.get(route('attendance.show', section.id), { date: newDate });
    };

    const markAll = (status) => {
        setMarks(Object.fromEntries(roster.map((r) => [r.enrollment_id, status])));
    };

    const submit = (e) => {
        e.preventDefault();
        setSaving(true);
        router.post(
            route('attendance.store', section.id),
            {
                date,
                records: Object.entries(marks).map(([enrollment_id, status]) => ({ enrollment_id, status })),
            },
            { onFinish: () => setSaving(false) }
        );
    };

    return (
        <AuthenticatedLayout
            header={<h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">{section.label} — Attendance</h2>}
        >
            <Head title={`Attendance — ${section.label}`} />

            <div className="py-8">
                <div className="mx-auto max-w-2xl px-4 sm:px-6 lg:px-8">
                    {flash?.success && (
                        <div className="mb-4 rounded-md bg-green-50 px-4 py-3 text-sm text-green-700 dark:bg-green-900/30 dark:text-green-300">
                            {flash.success}
                        </div>
                    )}

                    <div className="mb-4 flex items-center justify-between">
                        <input
                            type="date"
                            value={date}
                            onChange={(e) => changeDate(e.target.value)}
                            className="rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
                        />
                        <button
                            type="button"
                            className="text-sm text-indigo-600 hover:underline dark:text-indigo-400"
                            onClick={() => markAll('present')}
                        >
                            Mark everyone present
                        </button>
                    </div>

                    <form onSubmit={submit} className="space-y-2">
                        <div className="overflow-hidden rounded-lg bg-white shadow-sm dark:bg-gray-800">
                            {roster.map((r) => (
                                <div key={r.enrollment_id} className="flex flex-col gap-2 border-b border-gray-100 p-4 last:border-0 dark:border-gray-700 sm:flex-row sm:items-center sm:justify-between">
                                    <div>
                                        <p className="text-sm font-medium text-gray-900 dark:text-gray-100">{r.student_name}</p>
                                        <p className="text-xs text-gray-400">{r.student_id_number}</p>
                                    </div>
                                    <div className="flex flex-wrap gap-1.5">
                                        {STATUSES.map(([value, label, activeClass]) => (
                                            <button
                                                type="button"
                                                key={value}
                                                onClick={() => setMarks((m) => ({ ...m, [r.enrollment_id]: value }))}
                                                className={`rounded-full px-2.5 py-1 text-xs font-medium ${
                                                    marks[r.enrollment_id] === value
                                                        ? activeClass
                                                        : 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400'
                                                }`}
                                            >
                                                {label}
                                            </button>
                                        ))}
                                    </div>
                                </div>
                            ))}
                            {roster.length === 0 && (
                                <p className="p-4 text-center text-sm text-gray-400">No students enrolled in this section.</p>
                            )}
                        </div>

                        {roster.length > 0 && (
                            <PrimaryButton disabled={saving}>Save Attendance</PrimaryButton>
                        )}
                    </form>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
