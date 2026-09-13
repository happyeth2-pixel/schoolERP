import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

function AssignForm({ section, subjects, teachers, onDone }) {
    const { data, setData, post, processing } = useForm({
        section_id: section.id,
        subject_id: '',
        teacher_id: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('teaching-assignments.store'), { onSuccess: onDone });
    };

    return (
        <form onSubmit={submit} className="mt-2 flex flex-wrap items-end gap-2">
            <select
                className="rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
                value={data.subject_id}
                onChange={(e) => setData('subject_id', e.target.value)}
            >
                <option value="">Subject…</option>
                {subjects.map((s) => (
                    <option key={s.id} value={s.id}>{s.name}</option>
                ))}
            </select>
            <select
                className="rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
                value={data.teacher_id}
                onChange={(e) => setData('teacher_id', e.target.value)}
            >
                <option value="">Teacher…</option>
                {teachers.map((t) => (
                    <option key={t.id} value={t.id}>{t.name}</option>
                ))}
            </select>
            <button
                type="submit"
                disabled={processing || !data.subject_id || !data.teacher_id}
                className="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50"
            >
                Assign
            </button>
        </form>
    );
}

function ClassTeacherPicker({ section, teachers }) {
    const { data, setData, patch, processing } = useForm({
        class_teacher_id: section.class_teacher?.id ?? '',
    });

    const submit = (e) => {
        e.preventDefault();
        patch(route('sections.class-teacher', section.id));
    };

    return (
        <form onSubmit={submit} className="flex items-center gap-2 text-sm">
            <span className="text-gray-500">Class Teacher:</span>
            <select
                className="rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
                value={data.class_teacher_id}
                onChange={(e) => setData('class_teacher_id', e.target.value)}
            >
                <option value="">None</option>
                {teachers.map((t) => (
                    <option key={t.id} value={t.id}>{t.name}</option>
                ))}
            </select>
            <button
                type="submit"
                disabled={processing}
                className="text-indigo-600 hover:underline dark:text-indigo-400"
            >
                Save
            </button>
        </form>
    );
}

export default function Index({ sections, teachers, subjects }) {
    const { flash } = usePage().props;
    const [openSectionId, setOpenSectionId] = useState(null);

    const removeAssignment = (assignment) => {
        if (confirm(`Remove ${assignment.subject} — ${assignment.teacher}?`)) {
            router.delete(route('teaching-assignments.destroy', assignment.id));
        }
    };

    return (
        <AuthenticatedLayout
            header={<h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">Teaching Assignments</h2>}
        >
            <Head title="Teaching Assignments" />

            <div className="py-8">
                <div className="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8">
                    {flash?.success && (
                        <div className="mb-4 rounded-md bg-green-50 px-4 py-3 text-sm text-green-700 dark:bg-green-900/30 dark:text-green-300">
                            {flash.success}
                        </div>
                    )}

                    {teachers.length === 0 && (
                        <p className="mb-4 text-sm text-amber-600 dark:text-amber-400">
                            No teacher accounts yet — create some under People → Staff Accounts first.
                        </p>
                    )}

                    <div className="space-y-4">
                        {sections.map((section) => (
                            <div key={section.id} className="rounded-lg bg-white p-5 shadow-sm dark:bg-gray-800">
                                <div className="flex items-center justify-between">
                                    <p className="font-medium text-gray-900 dark:text-gray-100">{section.label}</p>
                                    <ClassTeacherPicker section={section} teachers={teachers} />
                                </div>

                                <ul className="mt-3 divide-y divide-gray-100 dark:divide-gray-700">
                                    {section.assignments.map((a) => (
                                        <li key={a.id} className="flex items-center justify-between py-1.5 text-sm">
                                            <span>{a.subject} — <span className="text-gray-500">{a.teacher}</span></span>
                                            <button className="text-red-600 hover:underline dark:text-red-400" onClick={() => removeAssignment(a)}>Remove</button>
                                        </li>
                                    ))}
                                    {section.assignments.length === 0 && (
                                        <li className="py-1.5 text-sm text-gray-400">No subjects assigned yet.</li>
                                    )}
                                </ul>

                                {openSectionId === section.id ? (
                                    <AssignForm section={section} subjects={subjects} teachers={teachers} onDone={() => setOpenSectionId(null)} />
                                ) : (
                                    <button className="mt-2 text-sm text-indigo-600 hover:underline dark:text-indigo-400" onClick={() => setOpenSectionId(section.id)}>
                                        + Assign a subject
                                    </button>
                                )}
                            </div>
                        ))}
                        {sections.length === 0 && (
                            <p className="text-center text-sm text-gray-400">No sections for the current academic year yet.</p>
                        )}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
