import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

function SelectField({ id, label, value, onChange, options, error }) {
    return (
        <div>
            <InputLabel htmlFor={id} value={label} />
            <select
                id={id}
                className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
                value={value}
                onChange={onChange}
            >
                <option value="">Select…</option>
                {options.map((o) => (
                    <option key={o.id} value={o.id}>{o.name}</option>
                ))}
            </select>
            <InputError message={error} className="mt-1" />
        </div>
    );
}

function SectionForm({ branches, grades, academicYears, onDone }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        branch_id: branches.length === 1 ? branches[0].id : '',
        grade_id: '',
        academic_year_id: academicYears.find((y) => y.is_current)?.id ?? '',
        name: '',
        capacity: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('sections.store'), { onSuccess: () => { reset(); onDone(); } });
    };

    return (
        <form onSubmit={submit} className="space-y-4 p-6">
            <h2 className="text-lg font-medium text-gray-900 dark:text-gray-100">Add Section</h2>

            {branches.length > 1 && (
                <SelectField
                    id="branch_id"
                    label="Branch"
                    value={data.branch_id}
                    onChange={(e) => setData('branch_id', e.target.value)}
                    options={branches}
                    error={errors.branch_id}
                />
            )}
            <SelectField
                id="grade_id"
                label="Grade"
                value={data.grade_id}
                onChange={(e) => setData('grade_id', e.target.value)}
                options={grades}
                error={errors.grade_id}
            />
            <SelectField
                id="academic_year_id"
                label="Academic Year"
                value={data.academic_year_id}
                onChange={(e) => setData('academic_year_id', e.target.value)}
                options={academicYears}
                error={errors.academic_year_id}
            />
            <div>
                <InputLabel htmlFor="name" value="Section Name (e.g. A)" />
                <TextInput id="name" className="mt-1 block w-full" value={data.name} onChange={(e) => setData('name', e.target.value)} />
                <InputError message={errors.name} className="mt-1" />
            </div>
            <div>
                <InputLabel htmlFor="capacity" value="Capacity (optional)" />
                <TextInput id="capacity" type="number" className="mt-1 block w-full" value={data.capacity} onChange={(e) => setData('capacity', e.target.value)} />
            </div>
            <div className="flex justify-end gap-3">
                <SecondaryButton type="button" onClick={onDone}>Cancel</SecondaryButton>
                <PrimaryButton disabled={processing}>Save</PrimaryButton>
            </div>
        </form>
    );
}

export default function Index({ sections, branches, grades, academicYears }) {
    const { flash, auth } = usePage().props;
    const canManage = auth.permissions.includes('manage-classes');
    const [showCreate, setShowCreate] = useState(false);

    const destroy = (section) => {
        if (confirm(`Remove ${section.grade.name} ${section.name}?`)) {
            router.delete(route('sections.destroy', section.id));
        }
    };

    return (
        <AuthenticatedLayout
            header={<h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">Sections</h2>}
        >
            <Head title="Sections" />

            <div className="py-8">
                <div className="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8">
                    {flash?.success && (
                        <div className="mb-4 rounded-md bg-green-50 px-4 py-3 text-sm text-green-700 dark:bg-green-900/30 dark:text-green-300">
                            {flash.success}
                        </div>
                    )}

                    {canManage && (
                        <div className="mb-4 flex justify-end">
                            <PrimaryButton
                                onClick={() => setShowCreate(true)}
                                disabled={grades.length === 0 || academicYears.length === 0}
                            >
                                Add Section
                            </PrimaryButton>
                        </div>
                    )}

                    {canManage && (grades.length === 0 || academicYears.length === 0) && (
                        <p className="mb-4 text-sm text-amber-600 dark:text-amber-400">
                            Set up at least one Grade and one Academic Year before creating sections.
                        </p>
                    )}

                    <div className="overflow-hidden rounded-lg bg-white shadow-sm dark:bg-gray-800">
                        <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead className="bg-gray-50 dark:bg-gray-900/40">
                                <tr>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Section</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Branch</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Academic Year</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Capacity</th>
                                    <th className="px-4 py-3" />
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
                                {sections.map((section) => (
                                    <tr key={section.id}>
                                        <td className="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">{section.grade.name} {section.name}</td>
                                        <td className="px-4 py-3 text-sm text-gray-500">{section.branch.name}</td>
                                        <td className="px-4 py-3 text-sm text-gray-500">{section.academic_year.name}</td>
                                        <td className="px-4 py-3 text-sm text-gray-500">{section.capacity ?? '—'}</td>
                                        <td className="px-4 py-3 text-right text-sm">
                                            {canManage && (
                                                <button className="text-red-600 hover:underline dark:text-red-400" onClick={() => destroy(section)}>Remove</button>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                                {sections.length === 0 && (
                                    <tr>
                                        <td colSpan={5} className="px-4 py-6 text-center text-sm text-gray-400">No sections yet.</td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <Modal show={showCreate} onClose={() => setShowCreate(false)}>
                <SectionForm branches={branches} grades={grades} academicYears={academicYears} onDone={() => setShowCreate(false)} />
            </Modal>
        </AuthenticatedLayout>
    );
}
