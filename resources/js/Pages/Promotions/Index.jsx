import PrimaryButton from '@/Components/PrimaryButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';

export default function Index({ sections, fromSectionId, students }) {
    const { flash, errors } = usePage().props;
    const [selected, setSelected] = useState(() => new Set(students.map((s) => s.enrollment_id)));
    const [graduating, setGraduating] = useState(() => new Set());
    const [toSectionId, setToSectionId] = useState('');
    const [processing, setProcessing] = useState(false);

    const changeFrom = (id) => {
        router.get(route('promotions.index'), id ? { from: id } : {}, { preserveState: false });
    };

    const toggleSelected = (id) => {
        setSelected((prev) => {
            const next = new Set(prev);
            next.has(id) ? next.delete(id) : next.add(id);

            return next;
        });
    };

    const toggleGraduating = (id) => {
        setGraduating((prev) => {
            const next = new Set(prev);
            next.has(id) ? next.delete(id) : next.add(id);

            return next;
        });
    };

    const destinationOptions = useMemo(() => sections.filter((s) => s.id !== fromSectionId), [sections, fromSectionId]);

    const submit = (e) => {
        e.preventDefault();
        const promotions = students
            .filter((s) => selected.has(s.enrollment_id))
            .map((s) => ({ enrollment_id: s.enrollment_id, graduate: graduating.has(s.enrollment_id) }));

        setProcessing(true);
        router.post(route('promotions.store'), {
            from_section_id: fromSectionId,
            to_section_id: toSectionId || null,
            promotions,
        }, { onFinish: () => setProcessing(false) });
    };

    const allNonGraduating = students.filter((s) => selected.has(s.enrollment_id) && !graduating.has(s.enrollment_id));

    return (
        <AuthenticatedLayout
            header={<h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">Promote Students</h2>}
        >
            <Head title="Promote Students" />

            <div className="py-8">
                <div className="mx-auto max-w-3xl space-y-4 px-4 sm:px-6 lg:px-8">
                    {flash?.success && (
                        <div className="rounded-md bg-green-50 px-4 py-3 text-sm text-green-700 dark:bg-green-900/30 dark:text-green-300">
                            {flash.success}
                        </div>
                    )}

                    <p className="text-sm text-gray-500">
                        Move a class's students into a new section — typically the next grade in a new academic year.
                        Create the destination academic year, grade and section first (under Academics), then come back here.
                        A student can instead be marked "Graduating" if this is their last grade.
                    </p>

                    <div className="rounded-lg bg-white p-4 shadow-sm dark:bg-gray-800">
                        <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">From section</label>
                        <select
                            className="mt-1 block w-full max-w-md rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
                            value={fromSectionId ?? ''}
                            onChange={(e) => changeFrom(e.target.value)}
                        >
                            <option value="">Select a section…</option>
                            {sections.map((s) => <option key={s.id} value={s.id}>{s.label}</option>)}
                        </select>
                    </div>

                    {fromSectionId && (
                        <form onSubmit={submit} className="space-y-4 rounded-lg bg-white p-4 shadow-sm dark:bg-gray-800">
                            <div>
                                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                    To section {allNonGraduating.length === 0 && <span className="text-gray-400">(not needed — everyone selected is graduating)</span>}
                                </label>
                                <select
                                    className="mt-1 block w-full max-w-md rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
                                    value={toSectionId}
                                    onChange={(e) => setToSectionId(e.target.value)}
                                    disabled={allNonGraduating.length === 0}
                                >
                                    <option value="">Select a destination…</option>
                                    {destinationOptions.map((s) => <option key={s.id} value={s.id}>{s.label}</option>)}
                                </select>
                                <p className="mt-1 text-xs text-red-600">{errors.to_section_id}</p>
                            </div>

                            <div className="overflow-hidden rounded-md border border-gray-200 dark:border-gray-700">
                                <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                    <thead className="bg-gray-50 dark:bg-gray-900/40">
                                        <tr>
                                            <th className="px-4 py-2 text-left text-xs font-medium uppercase text-gray-500">Include</th>
                                            <th className="px-4 py-2 text-left text-xs font-medium uppercase text-gray-500">Student</th>
                                            <th className="px-4 py-2 text-left text-xs font-medium uppercase text-gray-500">ID</th>
                                            <th className="px-4 py-2 text-left text-xs font-medium uppercase text-gray-500">Graduating instead</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
                                        {students.map((s) => (
                                            <tr key={s.enrollment_id}>
                                                <td className="px-4 py-2">
                                                    <input type="checkbox" checked={selected.has(s.enrollment_id)} onChange={() => toggleSelected(s.enrollment_id)} />
                                                </td>
                                                <td className="px-4 py-2 text-sm text-gray-900 dark:text-gray-100">{s.name}</td>
                                                <td className="px-4 py-2 text-sm text-gray-500">{s.student_id_number}</td>
                                                <td className="px-4 py-2">
                                                    <input
                                                        type="checkbox"
                                                        disabled={!selected.has(s.enrollment_id)}
                                                        checked={graduating.has(s.enrollment_id)}
                                                        onChange={() => toggleGraduating(s.enrollment_id)}
                                                    />
                                                </td>
                                            </tr>
                                        ))}
                                        {students.length === 0 && (
                                            <tr><td colSpan={4} className="px-4 py-6 text-center text-sm text-gray-400">No actively-enrolled students in this section.</td></tr>
                                        )}
                                    </tbody>
                                </table>
                            </div>

                            <PrimaryButton disabled={processing || selected.size === 0}>
                                Process {selected.size} student{selected.size === 1 ? '' : 's'}
                            </PrimaryButton>
                        </form>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
