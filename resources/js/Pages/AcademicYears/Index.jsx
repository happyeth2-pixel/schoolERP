import Checkbox from '@/Components/Checkbox';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

function YearForm({ year, onDone }) {
    const isEdit = !!year;
    const { data, setData, post, patch, processing, errors, reset } = useForm({
        name: year?.name ?? '',
        start_date: year?.start_date ?? '',
        end_date: year?.end_date ?? '',
        is_current: year?.is_current ?? false,
    });

    const submit = (e) => {
        e.preventDefault();
        const options = { onSuccess: () => { reset(); onDone(); } };
        if (isEdit) {
            patch(route('academic-years.update', year.id), options);
        } else {
            post(route('academic-years.store'), options);
        }
    };

    return (
        <form onSubmit={submit} className="space-y-4 p-6">
            <h2 className="text-lg font-medium text-gray-900 dark:text-gray-100">
                {isEdit ? 'Edit Academic Year' : 'Add Academic Year'}
            </h2>
            <div>
                <InputLabel htmlFor="name" value="Name (e.g. 2018/2019 E.C.)" />
                <TextInput id="name" className="mt-1 block w-full" value={data.name} onChange={(e) => setData('name', e.target.value)} />
                <InputError message={errors.name} className="mt-1" />
            </div>
            <div className="grid grid-cols-2 gap-4">
                <div>
                    <InputLabel htmlFor="start_date" value="Start Date" />
                    <TextInput id="start_date" type="date" className="mt-1 block w-full" value={data.start_date} onChange={(e) => setData('start_date', e.target.value)} />
                    <InputError message={errors.start_date} className="mt-1" />
                </div>
                <div>
                    <InputLabel htmlFor="end_date" value="End Date" />
                    <TextInput id="end_date" type="date" className="mt-1 block w-full" value={data.end_date} onChange={(e) => setData('end_date', e.target.value)} />
                    <InputError message={errors.end_date} className="mt-1" />
                </div>
            </div>
            <label className="flex items-center gap-2">
                <Checkbox checked={data.is_current} onChange={(e) => setData('is_current', e.target.checked)} />
                <span className="text-sm text-gray-600 dark:text-gray-400">Current academic year</span>
            </label>
            <div className="flex justify-end gap-3">
                <SecondaryButton type="button" onClick={onDone}>Cancel</SecondaryButton>
                <PrimaryButton disabled={processing}>Save</PrimaryButton>
            </div>
        </form>
    );
}

function TermForm({ academicYearId, term, onDone }) {
    const isEdit = !!term;
    const { data, setData, post, patch, processing, errors, reset } = useForm({
        name: term?.name ?? '',
        start_date: term?.start_date ?? '',
        end_date: term?.end_date ?? '',
    });

    const submit = (e) => {
        e.preventDefault();
        const options = { onSuccess: () => { reset(); onDone(); } };
        if (isEdit) {
            patch(route('terms.update', term.id), options);
        } else {
            post(route('terms.store', academicYearId), options);
        }
    };

    return (
        <form onSubmit={submit} className="space-y-4 p-6">
            <h2 className="text-lg font-medium text-gray-900 dark:text-gray-100">
                {isEdit ? 'Edit Term' : 'Add Term'}
            </h2>
            <div>
                <InputLabel htmlFor="term_name" value="Name (e.g. Term 1)" />
                <TextInput id="term_name" className="mt-1 block w-full" value={data.name} onChange={(e) => setData('name', e.target.value)} />
                <InputError message={errors.name} className="mt-1" />
            </div>
            <div className="grid grid-cols-2 gap-4">
                <div>
                    <InputLabel htmlFor="term_start" value="Start Date" />
                    <TextInput id="term_start" type="date" className="mt-1 block w-full" value={data.start_date} onChange={(e) => setData('start_date', e.target.value)} />
                </div>
                <div>
                    <InputLabel htmlFor="term_end" value="End Date" />
                    <TextInput id="term_end" type="date" className="mt-1 block w-full" value={data.end_date} onChange={(e) => setData('end_date', e.target.value)} />
                </div>
            </div>
            <div className="flex justify-end gap-3">
                <SecondaryButton type="button" onClick={onDone}>Cancel</SecondaryButton>
                <PrimaryButton disabled={processing}>Save</PrimaryButton>
            </div>
        </form>
    );
}

export default function Index({ academicYears }) {
    const { flash } = usePage().props;
    const [showCreate, setShowCreate] = useState(false);
    const [editingYear, setEditingYear] = useState(null);
    const [termFormFor, setTermFormFor] = useState(null);

    return (
        <AuthenticatedLayout
            header={<h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">Academic Years & Terms</h2>}
        >
            <Head title="Academic Years" />

            <div className="py-8">
                <div className="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
                    {flash?.success && (
                        <div className="mb-4 rounded-md bg-green-50 px-4 py-3 text-sm text-green-700 dark:bg-green-900/30 dark:text-green-300">
                            {flash.success}
                        </div>
                    )}

                    <div className="mb-4 flex justify-end">
                        <PrimaryButton onClick={() => setShowCreate(true)}>Add Academic Year</PrimaryButton>
                    </div>

                    <div className="space-y-4">
                        {academicYears.map((year) => (
                            <div key={year.id} className="rounded-lg bg-white p-5 shadow-sm dark:bg-gray-800">
                                <div className="flex items-center justify-between">
                                    <div>
                                        <p className="font-medium text-gray-900 dark:text-gray-100">
                                            {year.name}
                                            {year.is_current && (
                                                <span className="ml-2 rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-medium text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300">
                                                    Current
                                                </span>
                                            )}
                                        </p>
                                        <p className="text-sm text-gray-500">{year.start_date} → {year.end_date} · {year.terms_count} term(s)</p>
                                    </div>
                                    <div className="space-x-3 text-sm">
                                        <button className="text-indigo-600 hover:underline dark:text-indigo-400" onClick={() => setEditingYear(year)}>Edit</button>
                                        <button className="text-indigo-600 hover:underline dark:text-indigo-400" onClick={() => setTermFormFor(year.id)}>Add Term</button>
                                    </div>
                                </div>
                            </div>
                        ))}
                        {academicYears.length === 0 && (
                            <p className="text-center text-sm text-gray-400">No academic years yet.</p>
                        )}
                    </div>
                </div>
            </div>

            <Modal show={showCreate} onClose={() => setShowCreate(false)}>
                <YearForm onDone={() => setShowCreate(false)} />
            </Modal>
            <Modal show={!!editingYear} onClose={() => setEditingYear(null)}>
                {editingYear && <YearForm year={editingYear} onDone={() => setEditingYear(null)} />}
            </Modal>
            <Modal show={!!termFormFor} onClose={() => setTermFormFor(null)}>
                {termFormFor && <TermForm academicYearId={termFormFor} onDone={() => setTermFormFor(null)} />}
            </Modal>
        </AuthenticatedLayout>
    );
}
