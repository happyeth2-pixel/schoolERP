import SearchInput from '@/Components/SearchInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';

export default function Index({ parents }) {
    const { flash } = usePage().props;
    const [search, setSearch] = useState('');

    const filtered = useMemo(() => {
        const q = search.trim().toLowerCase();
        if (!q) return parents;
        return parents.filter((p) =>
            p.name.toLowerCase().includes(q) ||
            p.email.toLowerCase().includes(q) ||
            (p.phone || '').toLowerCase().includes(q) ||
            p.children.some((c) => c.name.toLowerCase().includes(q) || c.student_id_number.toLowerCase().includes(q))
        );
    }, [search, parents]);

    return (
        <AuthenticatedLayout
            header={<h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">Parents</h2>}
        >
            <Head title="Parents" />

            <div className="py-8">
                <div className="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8">
                    {flash?.success && (
                        <div className="mb-4 rounded-md bg-green-50 px-4 py-3 text-sm text-green-700 dark:bg-green-900/30 dark:text-green-300">
                            {flash.success}
                        </div>
                    )}

                    <p className="mb-4 text-sm text-gray-500">
                        Parent accounts are created from the Student enrollment form. This page is a directory of existing parents and the children linked to them.
                    </p>

                    <div className="mb-4 w-full max-w-xs">
                        <SearchInput value={search} onChange={setSearch} placeholder="Search parent or child…" />
                    </div>

                    <div className="space-y-3">
                        {filtered.map((p) => (
                            <div key={p.id} className="rounded-lg bg-white p-4 shadow-sm dark:bg-gray-800">
                                <p className="font-medium text-gray-900 dark:text-gray-100">{p.name}</p>
                                <p className="text-sm text-gray-500">{p.email}{p.phone ? ` · ${p.phone}` : ''}</p>
                                <ul className="mt-2 space-y-1">
                                    {p.children.map((c) => (
                                        <li key={c.id} className="text-sm text-gray-600 dark:text-gray-300">
                                            {c.name} <span className="text-gray-400">({c.relationship}) — {c.student_id_number}</span>
                                        </li>
                                    ))}
                                    {p.children.length === 0 && (
                                        <li className="text-sm text-gray-400">No children linked.</li>
                                    )}
                                </ul>
                            </div>
                        ))}
                        {filtered.length === 0 && (
                            <p className="text-center text-sm text-gray-400">
                                {parents.length === 0 ? 'No parent accounts yet.' : 'No parents match your search.'}
                            </p>
                        )}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
