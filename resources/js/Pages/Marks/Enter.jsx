import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

export default function Enter({ batch, exam, section, subject, roster }) {
    const { flash } = usePage().props;
    const [scores, setScores] = useState(() =>
        Object.fromEntries(roster.map((r) => [r.enrollment_id, r.score ?? '']))
    );
    const [maxScore, setMaxScore] = useState(roster[0]?.max_score ?? 100);
    const [saving, setSaving] = useState(false);
    const isDraft = batch.status === 'draft';

    const save = (andSubmit = false) => {
        setSaving(true);
        router.post(
            route('mark-batches.store', batch.id),
            {
                records: Object.entries(scores).map(([enrollment_id, score]) => ({
                    enrollment_id,
                    score: score === '' ? null : score,
                    max_score: maxScore,
                })),
            },
            {
                onSuccess: () => {
                    if (andSubmit) {
                        router.post(route('mark-batches.submit', batch.id));
                    }
                },
                onFinish: () => setSaving(false),
            }
        );
    };

    return (
        <AuthenticatedLayout
            header={<h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">{section.label} — {subject.name} — {exam.name}</h2>}
        >
            <Head title={`Marks — ${subject.name}`} />

            <div className="py-8">
                <div className="mx-auto max-w-2xl px-4 sm:px-6 lg:px-8">
                    {flash?.success && (
                        <div className="mb-4 rounded-md bg-green-50 px-4 py-3 text-sm text-green-700 dark:bg-green-900/30 dark:text-green-300">
                            {flash.success}
                        </div>
                    )}

                    <div className="mb-4 flex items-center justify-between">
                        <span className="rounded-full bg-gray-100 px-3 py-1 text-xs font-medium text-gray-600 dark:bg-gray-700 dark:text-gray-300">
                            Status: {batch.status}
                        </span>
                        {isDraft && (
                            <label className="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300">
                                Out of
                                <input
                                    type="number"
                                    className="w-20 rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
                                    value={maxScore}
                                    onChange={(e) => setMaxScore(e.target.value)}
                                />
                            </label>
                        )}
                    </div>

                    {!isDraft && (
                        <p className="mb-4 text-sm text-amber-600 dark:text-amber-400">
                            This batch is {batch.status} and can no longer be edited here.
                        </p>
                    )}

                    <div className="overflow-hidden rounded-lg bg-white shadow-sm dark:bg-gray-800">
                        {roster.map((r) => (
                            <div key={r.enrollment_id} className="flex items-center justify-between border-b border-gray-100 p-4 last:border-0 dark:border-gray-700">
                                <span className="text-sm text-gray-900 dark:text-gray-100">{r.student_name}</span>
                                <input
                                    type="number"
                                    step="0.01"
                                    disabled={!isDraft}
                                    className="w-24 rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 disabled:bg-gray-100 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:disabled:bg-gray-800"
                                    value={scores[r.enrollment_id]}
                                    onChange={(e) => setScores((s) => ({ ...s, [r.enrollment_id]: e.target.value }))}
                                />
                            </div>
                        ))}
                        {roster.length === 0 && (
                            <p className="p-4 text-center text-sm text-gray-400">No students enrolled in this section.</p>
                        )}
                    </div>

                    {isDraft && roster.length > 0 && (
                        <div className="mt-4 flex gap-3">
                            <SecondaryButton disabled={saving} onClick={() => save(false)}>Save Draft</SecondaryButton>
                            <PrimaryButton disabled={saving} onClick={() => save(true)}>Save & Submit for Approval</PrimaryButton>
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
