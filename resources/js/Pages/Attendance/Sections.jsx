import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';

export default function Sections({ sections }) {
    return (
        <AuthenticatedLayout
            header={<h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">Take Attendance</h2>}
        >
            <Head title="Take Attendance" />

            <div className="py-8">
                <div className="mx-auto max-w-2xl px-4 sm:px-6 lg:px-8">
                    {sections.length === 0 && (
                        <p className="text-sm text-gray-400">
                            You're not the class teacher of any section yet, so there's nothing to mark attendance for.
                        </p>
                    )}
                    <div className="space-y-3">
                        {sections.map((s) => (
                            <Link
                                key={s.id}
                                href={route('attendance.show', s.id)}
                                className="block rounded-lg bg-white p-4 shadow-sm hover:bg-gray-50 dark:bg-gray-800 dark:hover:bg-gray-700"
                            >
                                <span className="font-medium text-gray-900 dark:text-gray-100">{s.label}</span>
                            </Link>
                        ))}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
