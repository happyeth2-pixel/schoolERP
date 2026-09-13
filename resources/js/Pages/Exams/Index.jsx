import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

function ExamForm({ exam, terms, onDone }) {
    const isEdit = !!exam;
    const { data, setData, post, patch, processing, errors, reset } = useForm({
        term_id: terms[0]?.id ?? '',
        name: exam?.name ?? '',
        type: exam?.type ?? 'midterm',
        weight_percent: exam?.weight_percent ?? '',
    });

    const submit = (e) => {
        e.preventDefault();
        const options = { onSuccess: () => { reset(); onDone(); } };
        if (isEdit) {
            patch(route('exams.update', exam.id), options);
        } else {
            post(route('exams.store'), options);
        }
    };

    return (
        <form onSubmit={submit} className="space-y-4 p-6">
            <h2 className="text-lg font-medium text-gray-900 dark:text-gray-100">{isEdit ? 'Edit Exam' : 'Add Exam'}</h2>

            {!isEdit && (
                <div>
                    <InputLabel htmlFor="term_id" value="Term" />
                    <select
                        id="term_id"
                        className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
                        value={data.term_id}
                        onChange={(e) => setData('term_id', e.target.value)}
                    >
                        {terms.map((t) => <option key={t.id} value={t.id}>{t.label}</option>)}
                    </select>
                    <InputError message={errors.term_id} className="mt-1" />
                </div>
            )}

            <div>
                <InputLabel htmlFor="name" value="Name (e.g. Midterm)" />
                <TextInput id="name" className="mt-1 block w-full" value={data.name} onChange={(e) => setData('name', e.target.value)} />
                <InputError message={errors.name} className="mt-1" />
            </div>

            <div className="grid grid-cols-2 gap-4">
                <div>
                    <InputLabel htmlFor="type" value="Type" />
                    <select
                        id="type"
                        className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
                        value={data.type}
                        onChange={(e) => setData('type', e.target.value)}
                    >
                        {['quiz', 'assignment', 'midterm', 'final', 'other'].map((t) => <option key={t} value={t}>{t}</option>)}
                    </select>
                </div>
                <div>
                    <InputLabel htmlFor="weight_percent" value="Weight % of term grade" />
                    <TextInput id="weight_percent" type="number" step="0.01" className="mt-1 block w-full" value={data.weight_percent} onChange={(e) => setData('weight_percent', e.target.value)} />
                    <InputError message={errors.weight_percent} className="mt-1" />
                </div>
            </div>
            <p className="text-xs text-gray-400">
                Applies the same weight across every subject (e.g. "Midterm is worth 30% everywhere"). Make sure a term's exam weights add up to 100%.
            </p>

            <div className="flex justify-end gap-3">
                <SecondaryButton type="button" onClick={onDone}>Cancel</SecondaryButton>
                <PrimaryButton disabled={processing}>Save</PrimaryButton>
            </div>
        </form>
    );
}

export default function Index({ exams, terms }) {
    const { flash } = usePage().props;
    const [showCreate, setShowCreate] = useState(false);
    const [editing, setEditing] = useState(null);

    const destroy = (exam) => {
        if (confirm(`Remove ${exam.name}?`)) {
            router.delete(route('exams.destroy', exam.id));
        }
    };

    return (
        <AuthenticatedLayout
            header={<h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">Exams</h2>}
        >
            <Head title="Exams" />

            <div className="py-8">
                <div className="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
                    {flash?.success && (
                        <div className="mb-4 rounded-md bg-green-50 px-4 py-3 text-sm text-green-700 dark:bg-green-900/30 dark:text-green-300">
                            {flash.success}
                        </div>
                    )}

                    <div className="mb-4 flex justify-end">
                        <PrimaryButton onClick={() => setShowCreate(true)} disabled={terms.length === 0}>Add Exam</PrimaryButton>
                    </div>
                    {terms.length === 0 && (
                        <p className="mb-4 text-sm text-amber-600 dark:text-amber-400">
                            Add a Term under Academic Years & Terms before creating exams.
                        </p>
                    )}

                    <div className="overflow-hidden rounded-lg bg-white shadow-sm dark:bg-gray-800">
                        <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead className="bg-gray-50 dark:bg-gray-900/40">
                                <tr>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Name</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Term</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Type</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Weight</th>
                                    <th className="px-4 py-3" />
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
                                {exams.map((e) => (
                                    <tr key={e.id}>
                                        <td className="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">{e.name}</td>
                                        <td className="px-4 py-3 text-sm text-gray-500">{e.term}</td>
                                        <td className="px-4 py-3 text-sm text-gray-500">{e.type}</td>
                                        <td className="px-4 py-3 text-sm text-gray-500">{e.weight_percent}%</td>
                                        <td className="space-x-3 px-4 py-3 text-right text-sm">
                                            <button className="text-indigo-600 hover:underline dark:text-indigo-400" onClick={() => setEditing(e)}>Edit</button>
                                            <button className="text-red-600 hover:underline dark:text-red-400" onClick={() => destroy(e)}>Remove</button>
                                        </td>
                                    </tr>
                                ))}
                                {exams.length === 0 && (
                                    <tr><td colSpan={5} className="px-4 py-6 text-center text-sm text-gray-400">No exams yet.</td></tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <Modal show={showCreate} onClose={() => setShowCreate(false)}>
                <ExamForm terms={terms} onDone={() => setShowCreate(false)} />
            </Modal>
            <Modal show={!!editing} onClose={() => setEditing(null)}>
                {editing && <ExamForm exam={editing} terms={terms} onDone={() => setEditing(null)} />}
            </Modal>
        </AuthenticatedLayout>
    );
}
