import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PlusIcon, TrashIcon } from '@heroicons/react/24/outline';
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
            <h2 className="text-lg font-medium text-gray-900 dark:text-gray-100">{isEdit ? 'Edit Grade' : 'Add Grade'}</h2>
            <div>
                <InputLabel htmlFor="name" value="Grade Name (e.g. Grade 8, KG1)" />
                <TextInput id="name" className="mt-1 block w-full" value={data.name} onChange={(e) => setData('name', e.target.value)} />
                <InputError message={errors.name} className="mt-1" />
            </div>
            <div>
                <InputLabel htmlFor="sort_order" value="Sort Order (lower shows first)" />
                <TextInput id="sort_order" type="number" className="mt-1 block w-full" value={data.sort_order} onChange={(e) => setData('sort_order', e.target.value)} />
            </div>
            <div className="flex justify-end gap-3">
                <SecondaryButton type="button" onClick={onDone}>Cancel</SecondaryButton>
                <PrimaryButton disabled={processing}>Save</PrimaryButton>
            </div>
        </form>
    );
}

function SectionForm({ grade, branches, academicYears, onDone }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        branch_id: branches.length === 1 ? branches[0].id : '',
        grade_id: grade.id,
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
            <h2 className="text-lg font-medium text-gray-900 dark:text-gray-100">Add Section to {grade.name}</h2>
            {branches.length > 1 && (
                <div>
                    <InputLabel htmlFor="branch_id" value="Branch" />
                    <select
                        id="branch_id"
                        className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
                        value={data.branch_id}
                        onChange={(e) => setData('branch_id', e.target.value)}
                    >
                        <option value="">Select…</option>
                        {branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
                    </select>
                    <InputError message={errors.branch_id} className="mt-1" />
                </div>
            )}
            <div>
                <InputLabel htmlFor="academic_year_id" value="Academic Year" />
                <select
                    id="academic_year_id"
                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
                    value={data.academic_year_id}
                    onChange={(e) => setData('academic_year_id', e.target.value)}
                >
                    <option value="">Select…</option>
                    {academicYears.map((y) => <option key={y.id} value={y.id}>{y.name}</option>)}
                </select>
                <InputError message={errors.academic_year_id} className="mt-1" />
            </div>
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

export default function Index({ grades, branches, academicYears, canManage }) {
    const { flash } = usePage().props;
    const [showCreateGrade, setShowCreateGrade] = useState(false);
    const [editingGrade, setEditingGrade] = useState(null);
    const [addingSectionTo, setAddingSectionTo] = useState(null);

    const destroyGrade = (grade) => {
        if (confirm(`Remove ${grade.name}? This also removes its sections.`)) {
            router.delete(route('grades.destroy', grade.id));
        }
    };

    const destroySection = (section) => {
        if (confirm(`Remove section ${section.name}?`)) {
            router.delete(route('sections.destroy', section.id));
        }
    };

    return (
        <AuthenticatedLayout
            header={<h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">Grades & Sections</h2>}
        >
            <Head title="Grades & Sections" />

            <div className="py-8">
                <div className="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8">
                    {flash?.success && (
                        <div className="mb-4 rounded-md bg-green-50 px-4 py-3 text-sm text-green-700 dark:bg-green-900/30 dark:text-green-300">
                            {flash.success}
                        </div>
                    )}

                    {canManage && (
                        <div className="mb-4 flex justify-end">
                            <PrimaryButton onClick={() => setShowCreateGrade(true)}>
                                <PlusIcon className="mr-1.5 h-4 w-4" /> Add Grade
                            </PrimaryButton>
                        </div>
                    )}

                    <div className="space-y-4">
                        {grades.map((grade) => (
                            <div key={grade.id} className="rounded-lg bg-white p-5 shadow-sm dark:bg-gray-800">
                                <div className="flex items-center justify-between">
                                    <p className="font-medium text-gray-900 dark:text-gray-100">{grade.name}</p>
                                    {canManage && (
                                        <div className="space-x-3 text-sm">
                                            <button className="text-indigo-600 hover:underline dark:text-indigo-400" onClick={() => setAddingSectionTo(grade)}>+ Section</button>
                                            <button className="text-indigo-600 hover:underline dark:text-indigo-400" onClick={() => setEditingGrade(grade)}>Edit</button>
                                            <button className="text-red-600 hover:underline dark:text-red-400" onClick={() => destroyGrade(grade)}>Remove</button>
                                        </div>
                                    )}
                                </div>

                                <div className="mt-3 divide-y divide-gray-100 dark:divide-gray-700">
                                    {grade.sections.map((section) => (
                                        <div key={section.id} className="flex items-center justify-between py-2 text-sm">
                                            <span className="text-gray-700 dark:text-gray-300">
                                                {grade.name} {section.name}
                                                <span className="ml-2 text-gray-400">
                                                    {section.branch} · {section.academic_year}
                                                    {section.capacity ? ` · capacity ${section.capacity}` : ''}
                                                </span>
                                            </span>
                                            {canManage && (
                                                <button className="text-red-600 hover:underline dark:text-red-400" onClick={() => destroySection(section)}>
                                                    <TrashIcon className="h-4 w-4" />
                                                </button>
                                            )}
                                        </div>
                                    ))}
                                    {grade.sections.length === 0 && (
                                        <p className="py-2 text-sm text-gray-400">No sections yet for this grade.</p>
                                    )}
                                </div>
                            </div>
                        ))}
                        {grades.length === 0 && (
                            <p className="text-center text-sm text-gray-400">No grades yet.</p>
                        )}
                    </div>
                </div>
            </div>

            <Modal show={showCreateGrade} onClose={() => setShowCreateGrade(false)}>
                <GradeForm onDone={() => setShowCreateGrade(false)} />
            </Modal>
            <Modal show={!!editingGrade} onClose={() => setEditingGrade(null)}>
                {editingGrade && <GradeForm grade={editingGrade} onDone={() => setEditingGrade(null)} />}
            </Modal>
            <Modal show={!!addingSectionTo} onClose={() => setAddingSectionTo(null)}>
                {addingSectionTo && (
                    <SectionForm grade={addingSectionTo} branches={branches} academicYears={academicYears} onDone={() => setAddingSectionTo(null)} />
                )}
            </Modal>
        </AuthenticatedLayout>
    );
}
