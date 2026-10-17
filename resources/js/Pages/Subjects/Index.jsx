import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

function SubjectForm({ subject, onDone }) {
    const isEdit = !!subject;
    const { data, setData, post, patch, processing, errors, reset } = useForm({
        name: subject?.name ?? '',
        code: subject?.code ?? '',
    });

    const submit = (e) => {
        e.preventDefault();
        const options = { onSuccess: () => { reset(); onDone(); } };
        if (isEdit) {
            patch(route('subjects.update', subject.id), options);
        } else {
            post(route('subjects.store'), options);
        }
    };

    return (
        <form onSubmit={submit} className="space-y-4 p-6">
            <h2 className="text-lg font-medium text-gray-900 dark:text-gray-100">
                {isEdit ? 'Edit Subject' : 'Add Subject'}
            </h2>
            <div>
                <InputLabel htmlFor="name" value="Subject Name" />
                <TextInput
                    id="name"
                    className="mt-1 block w-full"
                    value={data.name}
                    onChange={(e) => setData('name', e.target.value)}
                />
                <InputError message={errors.name} className="mt-1" />
            </div>
            <div>
                <InputLabel htmlFor="code" value="Code (optional)" />
                <TextInput
                    id="code"
                    className="mt-1 block w-full"
                    value={data.code}
                    onChange={(e) => setData('code', e.target.value)}
                />
            </div>
            <div className="flex justify-end gap-3">
                <SecondaryButton type="button" onClick={onDone}>Cancel</SecondaryButton>
                <PrimaryButton disabled={processing}>Save</PrimaryButton>
            </div>
        </form>
    );
}

export default function Index({ subjects }) {
    const { t } = useTranslation();
    const { flash } = usePage().props;
    const [showCreate, setShowCreate] = useState(false);
    const [editing, setEditing] = useState(null);

    const destroy = (subject) => {
        if (confirm(t('confirm.subjects_remove', { name: subject.name }))) {
            router.delete(route('subjects.destroy', subject.id));
        }
    };

    return (
        <AuthenticatedLayout
            header={<h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">Subjects</h2>}
        >
            <Head title="Subjects" />

            <div className="py-8">
                <div className="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
                    {flash?.success && (
                        <div className="mb-4 rounded-md bg-green-50 px-4 py-3 text-sm text-green-700 dark:bg-green-900/30 dark:text-green-300">
                            {flash.success}
                        </div>
                    )}

                    <div className="mb-4 flex justify-end">
                        <PrimaryButton onClick={() => setShowCreate(true)}>Add Subject</PrimaryButton>
                    </div>

                    <div className="overflow-x-auto rounded-lg bg-white shadow-sm dark:bg-gray-800">
                        <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead className="bg-gray-50 dark:bg-gray-900/40">
                                <tr>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Name</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Code</th>
                                    <th className="px-4 py-3" />
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
                                {subjects.map((subject) => (
                                    <tr key={subject.id}>
                                        <td className="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">{subject.name}</td>
                                        <td className="px-4 py-3 text-sm text-gray-500">{subject.code}</td>
                                        <td className="space-x-3 px-4 py-3 text-right text-sm">
                                            <button className="text-indigo-600 hover:underline dark:text-indigo-400" onClick={() => setEditing(subject)}>Edit</button>
                                            <button className="text-red-600 hover:underline dark:text-red-400" onClick={() => destroy(subject)}>Remove</button>
                                        </td>
                                    </tr>
                                ))}
                                {subjects.length === 0 && (
                                    <tr>
                                        <td colSpan={3} className="px-4 py-6 text-center text-sm text-gray-400">No subjects yet.</td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <Modal show={showCreate} onClose={() => setShowCreate(false)}>
                <SubjectForm onDone={() => setShowCreate(false)} />
            </Modal>
            <Modal show={!!editing} onClose={() => setEditing(null)}>
                {editing && <SubjectForm subject={editing} onDone={() => setEditing(null)} />}
            </Modal>
        </AuthenticatedLayout>
    );
}
