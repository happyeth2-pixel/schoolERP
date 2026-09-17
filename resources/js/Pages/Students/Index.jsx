import PrimaryButton from '@/Components/PrimaryButton';
import SearchInput from '@/Components/SearchInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { ArrowDownTrayIcon } from '@heroicons/react/24/outline';
import { Head, Link, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';

export default function Index({ students }) {
    const { flash, auth } = usePage().props;
    const canManage = auth.permissions.includes('manage-students');
    const [search, setSearch] = useState('');

    const filtered = useMemo(() => {
        const q = search.trim().toLowerCase();
        if (!q) return students;
        return students.filter((s) =>
            s.name.toLowerCase().includes(q) ||
            s.student_id_number.toLowerCase().includes(q) ||
            s.section.toLowerCase().includes(q) ||
            s.guardians.some((g) => g.toLowerCase().includes(q))
        );
    }, [search, students]);

    return (
        <AuthenticatedLayout
            header={<h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">Students</h2>}
        >
            <Head title="Students" />

            <div className="py-8">
                <div className="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">
                    {flash?.success && (
                        <div className="mb-4 rounded-md bg-green-50 px-4 py-3 text-sm text-green-700 dark:bg-green-900/30 dark:text-green-300">
                            {flash.success}
                        </div>
                    )}

                    <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                        <div className="w-full max-w-xs">
                            <SearchInput value={search} onChange={setSearch} placeholder="Search name, ID, section, guardian…" />
                        </div>
                        <div className="flex gap-3">
                            <a
                                href={route('students.export')}
                                className="inline-flex items-center gap-1.5 rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
                            >
                                <ArrowDownTrayIcon className="h-4 w-4" /> Export to Excel
                            </a>
                            {canManage && (
                                <Link href={route('students.create')}>
                                    <PrimaryButton>Enroll Student</PrimaryButton>
                                </Link>
                            )}
                        </div>
                    </div>

                    <div className="overflow-hidden rounded-lg bg-white shadow-sm dark:bg-gray-800">
                        <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead className="bg-gray-50 dark:bg-gray-900/40">
                                <tr>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">ID</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Name</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Section</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Guardians</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Status</th>
                                    <th className="px-4 py-3" />
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
                                {filtered.map((s) => (
                                    <tr key={s.id}>
                                        <td className="px-4 py-3 text-sm text-gray-500">{s.student_id_number}</td>
                                        <td className="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">{s.name}</td>
                                        <td className="px-4 py-3 text-sm text-gray-500">{s.section}</td>
                                        <td className="px-4 py-3 text-sm text-gray-500">{s.guardians.join(', ') || '—'}</td>
                                        <td className="px-4 py-3 text-sm">
                                            <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${s.status === 'active' ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300' : 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400'}`}>
                                                {s.status}
                                            </span>
                                        </td>
                                        <td className="space-x-3 px-4 py-3 text-right text-sm">
                                            <Link href={route('report-card.show', s.id)} className="text-indigo-600 hover:underline dark:text-indigo-400">Report Card</Link>
                                            <Link href={route('students.edit', s.id)} className="text-indigo-600 hover:underline dark:text-indigo-400">{canManage ? 'Edit' : 'View'}</Link>
                                        </td>
                                    </tr>
                                ))}
                                {filtered.length === 0 && (
                                    <tr>
                                        <td colSpan={6} className="px-4 py-6 text-center text-sm text-gray-400">
                                            {students.length === 0 ? 'No students enrolled yet.' : 'No students match your search.'}
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
