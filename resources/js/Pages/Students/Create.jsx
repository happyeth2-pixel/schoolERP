import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';

function Field({ id, label, children }) {
    return (
        <div>
            <InputLabel htmlFor={id} value={label} />
            {children}
        </div>
    );
}

export default function Create({ sections }) {
    const { data, setData, post, processing, errors } = useForm({
        first_name: '',
        father_name: '',
        grandfather_name: '',
        gender: 'male',
        date_of_birth: '',
        address: '',
        emergency_contact: '',
        previous_school: '',
        enrollment_date: new Date().toISOString().slice(0, 10),
        section_id: sections.length === 1 ? sections[0].id : '',
        guardian_mode: 'new',
        guardian_email: '',
        guardian_name: '',
        guardian_phone: '',
        guardian_relationship: 'father',
    });

    const [lookupState, setLookupState] = useState(null); // null | 'checking' | 'found' | 'not_found'

    useEffect(() => {
        if (data.guardian_mode !== 'existing' || !data.guardian_email) {
            setLookupState(null);
            return;
        }
        const handle = setTimeout(() => {
            setLookupState('checking');
            fetch(route('parents.search', { email: data.guardian_email }))
                .then((r) => r.json())
                .then((json) => setLookupState(json.guardian ? 'found' : 'not_found'));
        }, 400);
        return () => clearTimeout(handle);
    }, [data.guardian_email, data.guardian_mode]);

    const submit = (e) => {
        e.preventDefault();
        post(route('students.store'));
    };

    return (
        <AuthenticatedLayout
            header={<h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">Enroll Student</h2>}
        >
            <Head title="Enroll Student" />

            <div className="py-8">
                <div className="mx-auto max-w-2xl px-4 sm:px-6 lg:px-8">
                    <form onSubmit={submit} className="space-y-6 rounded-lg bg-white p-6 shadow-sm dark:bg-gray-800">
                        <div>
                            <h3 className="mb-3 text-sm font-semibold uppercase tracking-wide text-gray-400">Student</h3>
                            <div className="grid grid-cols-3 gap-4">
                                <Field id="first_name" label="First Name">
                                    <TextInput id="first_name" className="mt-1 block w-full" value={data.first_name} onChange={(e) => setData('first_name', e.target.value)} />
                                    <InputError message={errors.first_name} className="mt-1" />
                                </Field>
                                <Field id="father_name" label="Father's Name">
                                    <TextInput id="father_name" className="mt-1 block w-full" value={data.father_name} onChange={(e) => setData('father_name', e.target.value)} />
                                    <InputError message={errors.father_name} className="mt-1" />
                                </Field>
                                <Field id="grandfather_name" label="Grandfather's Name">
                                    <TextInput id="grandfather_name" className="mt-1 block w-full" value={data.grandfather_name} onChange={(e) => setData('grandfather_name', e.target.value)} />
                                </Field>
                            </div>

                            <div className="mt-4 grid grid-cols-2 gap-4">
                                <Field id="gender" label="Gender">
                                    <select
                                        id="gender"
                                        className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
                                        value={data.gender}
                                        onChange={(e) => setData('gender', e.target.value)}
                                    >
                                        <option value="male">Male</option>
                                        <option value="female">Female</option>
                                    </select>
                                </Field>
                                <Field id="date_of_birth" label="Date of Birth">
                                    <TextInput id="date_of_birth" type="date" className="mt-1 block w-full" value={data.date_of_birth} onChange={(e) => setData('date_of_birth', e.target.value)} />
                                </Field>
                            </div>

                            <div className="mt-4 grid grid-cols-2 gap-4">
                                <Field id="address" label="Address">
                                    <TextInput id="address" className="mt-1 block w-full" value={data.address} onChange={(e) => setData('address', e.target.value)} />
                                </Field>
                                <Field id="emergency_contact" label="Emergency Contact">
                                    <TextInput id="emergency_contact" className="mt-1 block w-full" value={data.emergency_contact} onChange={(e) => setData('emergency_contact', e.target.value)} />
                                </Field>
                            </div>

                            <div className="mt-4">
                                <Field id="previous_school" label="Previous School (optional)">
                                    <TextInput id="previous_school" className="mt-1 block w-full" value={data.previous_school} onChange={(e) => setData('previous_school', e.target.value)} />
                                </Field>
                            </div>
                        </div>

                        <div>
                            <h3 className="mb-3 text-sm font-semibold uppercase tracking-wide text-gray-400">Enrollment</h3>
                            <div className="grid grid-cols-2 gap-4">
                                <Field id="enrollment_date" label="Enrollment Date">
                                    <TextInput id="enrollment_date" type="date" className="mt-1 block w-full" value={data.enrollment_date} onChange={(e) => setData('enrollment_date', e.target.value)} />
                                </Field>
                                <Field id="section_id" label="Section">
                                    <select
                                        id="section_id"
                                        className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
                                        value={data.section_id}
                                        onChange={(e) => setData('section_id', e.target.value)}
                                    >
                                        <option value="">Select…</option>
                                        {sections.map((s) => (
                                            <option key={s.id} value={s.id}>{s.label}</option>
                                        ))}
                                    </select>
                                    <InputError message={errors.section_id} className="mt-1" />
                                </Field>
                            </div>
                            {sections.length === 0 && (
                                <p className="mt-2 text-sm text-amber-600 dark:text-amber-400">
                                    No sections exist for the current academic year yet — set one up under Academics → Sections first.
                                </p>
                            )}
                        </div>

                        <div>
                            <h3 className="mb-3 text-sm font-semibold uppercase tracking-wide text-gray-400">Parent / Guardian</h3>
                            <div className="mb-3 flex gap-4 text-sm">
                                {[['new', 'Create new parent account'], ['existing', 'Link existing parent'], ['none', 'Add later']].map(([value, label]) => (
                                    <label key={value} className="flex items-center gap-2 text-gray-700 dark:text-gray-300">
                                        <input type="radio" name="guardian_mode" value={value} checked={data.guardian_mode === value} onChange={(e) => setData('guardian_mode', e.target.value)} />
                                        {label}
                                    </label>
                                ))}
                            </div>

                            {data.guardian_mode !== 'none' && (
                                <div className="space-y-4">
                                    <div className="grid grid-cols-2 gap-4">
                                        <Field id="guardian_email" label="Guardian Email">
                                            <TextInput id="guardian_email" type="email" className="mt-1 block w-full" value={data.guardian_email} onChange={(e) => setData('guardian_email', e.target.value)} />
                                            {lookupState === 'checking' && <p className="mt-1 text-xs text-gray-400">Checking…</p>}
                                            {data.guardian_mode === 'existing' && lookupState === 'found' && <p className="mt-1 text-xs text-green-600">Parent account found.</p>}
                                            {data.guardian_mode === 'existing' && lookupState === 'not_found' && <p className="mt-1 text-xs text-red-600">No parent account with that email — switch to "Create new".</p>}
                                            <InputError message={errors.guardian_email} className="mt-1" />
                                        </Field>
                                        <Field id="guardian_relationship" label="Relationship">
                                            <select
                                                id="guardian_relationship"
                                                className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
                                                value={data.guardian_relationship}
                                                onChange={(e) => setData('guardian_relationship', e.target.value)}
                                            >
                                                <option value="father">Father</option>
                                                <option value="mother">Mother</option>
                                                <option value="guardian">Guardian</option>
                                            </select>
                                        </Field>
                                    </div>
                                    {data.guardian_mode === 'new' && (
                                        <div className="grid grid-cols-2 gap-4">
                                            <Field id="guardian_name" label="Guardian Full Name">
                                                <TextInput id="guardian_name" className="mt-1 block w-full" value={data.guardian_name} onChange={(e) => setData('guardian_name', e.target.value)} />
                                                <InputError message={errors.guardian_name} className="mt-1" />
                                            </Field>
                                            <Field id="guardian_phone" label="Guardian Phone">
                                                <TextInput id="guardian_phone" className="mt-1 block w-full" value={data.guardian_phone} onChange={(e) => setData('guardian_phone', e.target.value)} />
                                            </Field>
                                        </div>
                                    )}
                                </div>
                            )}
                        </div>

                        <PrimaryButton disabled={processing || sections.length === 0}>Enroll Student</PrimaryButton>
                    </form>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
