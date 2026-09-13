import DangerButton from '@/Components/DangerButton';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

function GradeForm({ grade, onDone }) {
    const isEdit = !!grade;
    const { data, setData, post, patch, processing, errors, reset } = useForm({
        name: grade?.name ?? '',
        sort_order: grade?.sort_order ?? 0,
    });

    const submit = (e) => {
        e.preventDefault();
        const options = { onSuccess: () => { reset(); onDone(); } };
        if (isEdit) {
            patch(route('grades.update', grade.id), options);
        } else {
            post(route('grades.store'), options);
        }
    };

    return (
        <form onSubmit={submit} className="space-y-4 p-6">
            <h2 className="text-lg font-medium text-gray-900 dark:text-gray-100">
                {isEdit ? 'Edit Grade' : 'Add Grade'}
            </h2>
            <div>
                <InputLabel htmlFor="name" value="Grade Name (e.g. Grade 8, KG1)" />
                <TextInput
                    id="name"
                    className="mt-1 block w-full"
                    value={data.name}
                    onChange={(e) => setData('name', e.target.value)}
                />
                <InputError message={errors.name} className="mt-1" />
            </div>
            <div>
                <InputLabel htmlFor="sort_order" value="Sort Order (lower shows first)" />
                <TextInput
                    id="sort_order"
                    type="number"
                    className="mt-1 block w-full"
                    value={data.sort_order}
                    onChange={(e) => setData('sort_order', e.target.value)}
                />
            </div>
            <div className="flex justify-end gap-3">
                <SecondaryButton type="button" onClick={onDone}>Cancel</SecondaryButton>
                <PrimaryButton disabled={processing}>Save</PrimaryButton>
            </div>
        </form>
    );
}

export default function Index({ grades }) {
    const { flash } = usePage().props;
    const [showCreate, setShowCreate] = useState(false);
    const [editing, setEditing] = useState(null);

    const destroy = (grade) => {
        if (confirm(`Remove ${grade.name}?`)) {
            router.delete(route('grades.destroy', grade.id));
        }
    };

    return (
        <AuthenticatedLayout
            header={<h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">Grades</h2>}
        >
            <Head title="Grades" />

            <div className="py-8">
                <div className="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
                    {flash?.success && (
                        <div className="mb-4 rounded-md bg-green-50 px-4 py-3 text-sm text-green-700 dark:bg-green-900/30 dark:text-green-300">
                            {flash.success}
                        </div>
                    )}

                    <div className="mb-4 flex justify-end">
                        <PrimaryButton onClick={() => setShowCreate(true)}>Add Grade</PrimaryButton>
                    </div>

                    <div className="overflow-hidden rounded-lg bg-white shadow-sm dark:bg-gray-800">
                        <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead className="bg-gray-50 dark:bg-gray-900/40">
                                <tr>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Name</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Sort Order</th>
                                    <th className="px-4 py-3" />
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
                                {grades.map((grade) => (
                                    <tr key={grade.id}>
                                        <td className="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">{grade.name}</td>
                                        <td className="px-4 py-3 text-sm text-gray-500">{grade.sort_order}</td>
                                        <td className="space-x-3 px-4 py-3 text-right text-sm">
                                            <button className="text-indigo-600 hover:underline dark:text-indigo-400" onClick={() => setEditing(grade)}>Edit</button>
                                            <button className="text-red-600 hover:underline dark:text-red-400" onClick={() => destroy(grade)}>Remove</button>
                                        </td>
                                    </tr>
                                ))}
                                {grades.length === 0 && (
                                    <tr>
                                        <td colSpan={3} className="px-4 py-6 text-center text-sm text-gray-400">No grades yet.</td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <Modal show={showCreate} onClose={() => setShowCreate(false)}>
                <GradeForm onDone={() => setShowCreate(false)} />
            </Modal>
            <Modal show={!!editing} onClose={() => setEditing(null)}>
                {editing && <GradeForm grade={editing} onDone={() => setEditing(null)} />}
            </Modal>
        </AuthenticatedLayout>
    );
}
