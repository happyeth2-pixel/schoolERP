import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

function AddGuardianForm({ studentId, onDone }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        guardian_mode: 'existing',
        guardian_email: '',
        guardian_name: '',
        guardian_phone: '',
        guardian_relationship: 'father',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('students.guardians.store', studentId), {
            onSuccess: () => { reset(); onDone(); },
        });
    };

    return (
        <form onSubmit={submit} className="mt-4 space-y-3 rounded-md border border-gray-200 p-4 dark:border-gray-700">
            <div className="flex gap-4 text-sm">
                {[['existing', 'Existing parent'], ['new', 'New parent']].map(([value, label]) => (
                    <label key={value} className="flex items-center gap-2 text-gray-700 dark:text-gray-300">
                        <input type="radio" name="guardian_mode" value={value} checked={data.guardian_mode === value} onChange={(e) => setData('guardian_mode', e.target.value)} />
                        {label}
                    </label>
                ))}
            </div>
            <div className="grid grid-cols-2 gap-3">
                <div>
                    <InputLabel htmlFor="ng_email" value="Email" />
                    <TextInput id="ng_email" type="email" className="mt-1 block w-full" value={data.guardian_email} onChange={(e) => setData('guardian_email', e.target.value)} />
                    <InputError message={errors.guardian_email} className="mt-1" />
                </div>
                <div>
                    <InputLabel htmlFor="ng_rel" value="Relationship" />
                    <select
                        id="ng_rel"
                        className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
                        value={data.guardian_relationship}
                        onChange={(e) => setData('guardian_relationship', e.target.value)}
                    >
                        <option value="father">Father</option>
                        <option value="mother">Mother</option>
                        <option value="guardian">Guardian</option>
                    </select>
                </div>
            </div>
            {data.guardian_mode === 'new' && (
                <div className="grid grid-cols-2 gap-3">
                    <div>
                        <InputLabel htmlFor="ng_name" value="Full Name" />
                        <TextInput id="ng_name" className="mt-1 block w-full" value={data.guardian_name} onChange={(e) => setData('guardian_name', e.target.value)} />
                        <InputError message={errors.guardian_name} className="mt-1" />
                    </div>
                    <div>
                        <InputLabel htmlFor="ng_phone" value="Phone" />
                        <TextInput id="ng_phone" className="mt-1 block w-full" value={data.guardian_phone} onChange={(e) => setData('guardian_phone', e.target.value)} />
                    </div>
                </div>
            )}
            <div className="flex justify-end gap-3">
                <SecondaryButton type="button" onClick={onDone}>Cancel</SecondaryButton>
                <PrimaryButton disabled={processing}>Link Guardian</PrimaryButton>
            </div>
        </form>
    );
}

export default function Edit({ student, canManage }) {
    const { flash } = usePage().props;
    const [addingGuardian, setAddingGuardian] = useState(false);
    const { data, setData, patch, processing, errors } = useForm({
        first_name: student.first_name,
        father_name: student.father_name,
        grandfather_name: student.grandfather_name ?? '',
        gender: student.gender,
        date_of_birth: student.date_of_birth ?? '',
        address: student.address ?? '',
        emergency_contact: student.emergency_contact ?? '',
        previous_school: student.previous_school ?? '',
        status: student.status,
    });

    const submit = (e) => {
        e.preventDefault();
        patch(route('students.update', student.id));
    };

    const removeGuardian = (guardian) => {
        if (confirm(`Unlink ${guardian.name}?`)) {
            router.delete(route('students.guardians.destroy', [student.id, guardian.id]));
        }
    };

    return (
        <AuthenticatedLayout
            header={<h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">{student.student_id_number}</h2>}
        >
            <Head title={`Edit ${student.first_name}`} />

            <div className="py-8">
                <div className="mx-auto max-w-2xl space-y-6 px-4 sm:px-6 lg:px-8">
                    {flash?.success && (
                        <div className="rounded-md bg-green-50 px-4 py-3 text-sm text-green-700 dark:bg-green-900/30 dark:text-green-300">
                            {flash.success}
                        </div>
                    )}

                    <a href={route('report-card.show', student.id)} className="inline-block text-sm text-indigo-600 hover:underline dark:text-indigo-400">
                        View Report Card
                    </a>

                    {!canManage && (
                        <div className="rounded-md bg-gray-100 px-4 py-3 text-sm text-gray-600 dark:bg-gray-700 dark:text-gray-300">
                            Read-only — you have oversight access but cannot make changes here.
                        </div>
                    )}

                    <form onSubmit={submit} className="space-y-4 rounded-lg bg-white p-6 shadow-sm dark:bg-gray-800">
                    <fieldset disabled={!canManage} className="space-y-4">
                        <div className="grid grid-cols-3 gap-4">
                            <div>
                                <InputLabel htmlFor="first_name" value="First Name" />
                                <TextInput id="first_name" className="mt-1 block w-full" value={data.first_name} onChange={(e) => setData('first_name', e.target.value)} />
                                <InputError message={errors.first_name} className="mt-1" />
                            </div>
                            <div>
                                <InputLabel htmlFor="father_name" value="Father's Name" />
                                <TextInput id="father_name" className="mt-1 block w-full" value={data.father_name} onChange={(e) => setData('father_name', e.target.value)} />
                                <InputError message={errors.father_name} className="mt-1" />
                            </div>
                            <div>
                                <InputLabel htmlFor="grandfather_name" value="Grandfather's Name" />
                                <TextInput id="grandfather_name" className="mt-1 block w-full" value={data.grandfather_name} onChange={(e) => setData('grandfather_name', e.target.value)} />
                            </div>
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div>
                                <InputLabel htmlFor="gender" value="Gender" />
                                <select
                                    id="gender"
                                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
                                    value={data.gender}
                                    onChange={(e) => setData('gender', e.target.value)}
                                >
                                    <option value="male">Male</option>
                                    <option value="female">Female</option>
                                </select>
                            </div>
                            <div>
                                <InputLabel htmlFor="date_of_birth" value="Date of Birth" />
                                <TextInput id="date_of_birth" type="date" className="mt-1 block w-full" value={data.date_of_birth} onChange={(e) => setData('date_of_birth', e.target.value)} />
                            </div>
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div>
                                <InputLabel htmlFor="address" value="Address" />
                                <TextInput id="address" className="mt-1 block w-full" value={data.address} onChange={(e) => setData('address', e.target.value)} />
                            </div>
                            <div>
                                <InputLabel htmlFor="emergency_contact" value="Emergency Contact" />
                                <TextInput id="emergency_contact" className="mt-1 block w-full" value={data.emergency_contact} onChange={(e) => setData('emergency_contact', e.target.value)} />
                            </div>
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div>
                                <InputLabel htmlFor="previous_school" value="Previous School" />
                                <TextInput id="previous_school" className="mt-1 block w-full" value={data.previous_school} onChange={(e) => setData('previous_school', e.target.value)} />
                            </div>
                            <div>
                                <InputLabel htmlFor="status" value="Status" />
                                <select
                                    id="status"
                                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
                                    value={data.status}
                                    onChange={(e) => setData('status', e.target.value)}
                                >
                                    {['active', 'transferred', 'graduated', 'withdrawn', 'suspended', 'expelled', 'inactive'].map((s) => (
                                        <option key={s} value={s}>{s}</option>
                                    ))}
                                </select>
                            </div>
                        </div>

                        <p className="text-sm text-gray-500">Current section: {student.section ?? 'Unassigned'}</p>

                        {canManage && <PrimaryButton disabled={processing}>Save</PrimaryButton>}
                    </fieldset>
                    </form>

                    <div className="rounded-lg bg-white p-6 shadow-sm dark:bg-gray-800">
                        <div className="mb-3 flex items-center justify-between">
                            <h3 className="text-sm font-semibold uppercase tracking-wide text-gray-400">Guardians</h3>
                            {canManage && !addingGuardian && (
                                <button className="text-sm text-indigo-600 hover:underline dark:text-indigo-400" onClick={() => setAddingGuardian(true)}>
                                    Add Guardian
                                </button>
                            )}
                        </div>

                        <ul className="divide-y divide-gray-200 dark:divide-gray-700">
                            {student.guardians.map((g) => (
                                <li key={g.id} className="flex items-center justify-between py-2 text-sm">
                                    <span>{g.name} <span className="text-gray-400">({g.relationship}) — {g.email}</span></span>
                                    {canManage && (
                                        <button className="text-red-600 hover:underline dark:text-red-400" onClick={() => removeGuardian(g)}>Unlink</button>
                                    )}
                                </li>
                            ))}
                            {student.guardians.length === 0 && (
                                <li className="py-2 text-sm text-gray-400">No guardians linked yet.</li>
                            )}
                        </ul>

                        {canManage && addingGuardian && (
                            <AddGuardianForm studentId={student.id} onDone={() => setAddingGuardian(false)} />
                        )}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
