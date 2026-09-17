import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';

export default function Create({ children }) {
    const { data, setData, post, processing, errors } = useForm({
        student_id: '',
        subject: '',
        body: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('complaints.store'));
    };

    return (
        <AuthenticatedLayout
            header={<h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">New Complaint</h2>}
        >
            <Head title="New Complaint" />

            <div className="py-8">
                <div className="mx-auto max-w-2xl px-4 sm:px-6 lg:px-8">
                    <form onSubmit={submit} className="space-y-4 rounded-lg bg-white p-6 shadow-sm dark:bg-gray-800">
                        {children.length > 0 && (
                            <div>
                                <InputLabel htmlFor="student_id" value="About which child? (optional)" />
                                <select
                                    id="student_id"
                                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
                                    value={data.student_id}
                                    onChange={(e) => setData('student_id', e.target.value)}
                                >
                                    <option value="">General / not specific to one child</option>
                                    {children.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                                </select>
                            </div>
                        )}

                        <div>
                            <InputLabel htmlFor="subject" value="Subject" />
                            <TextInput id="subject" className="mt-1 block w-full" value={data.subject} onChange={(e) => setData('subject', e.target.value)} />
                            <InputError message={errors.subject} className="mt-1" />
                        </div>

                        <div>
                            <InputLabel htmlFor="body" value="Details" />
                            <textarea
                                id="body"
                                rows={6}
                                className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
                                value={data.body}
                                onChange={(e) => setData('body', e.target.value)}
                            />
                            <InputError message={errors.body} className="mt-1" />
                        </div>

                        <PrimaryButton disabled={processing}>Submit Complaint</PrimaryButton>
                    </form>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
