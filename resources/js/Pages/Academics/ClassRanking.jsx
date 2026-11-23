import { BackLink, Button, Card, CheckRow, EmptyState, Page, StatusBadge } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { ArchiveBoxIcon, DocumentArrowDownIcon, IdentificationIcon, TrophyIcon } from '@heroicons/react/24/outline';
import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';

// The homeroom teacher's and the principal's shared screen: pick which subjects count toward this class's
// ranking, then see exactly who is complete (ranked, with an average) and who isn't yet, and why -- the
// same ReportCardCalculator rules every individual report card already uses, just all of the class at once.
export default function ClassRanking({ section, roster, requiredSubjects, allSubjects, canManageRequiredSubjects, canExport, classCodesUrl }) {
    const [editingSubjects, setEditingSubjects] = useState(false);
    const { data, setData, post, processing } = useForm({
        subject_ids: requiredSubjects.map((s) => s.id),
    });

    const toggle = (id, checked) => {
        setData('subject_ids', checked ? [...data.subject_ids, id] : data.subject_ids.filter((x) => x !== id));
    };

    const save = () => {
        post(route('sections.required-subjects', section.id), {
            preserveScroll: true,
            onSuccess: () => setEditingSubjects(false),
        });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">Class Ranking — {section.name}</h2>}>
            <Head title={`Class Ranking — ${section.name}`} />
            <Page
                title={`Class Ranking — ${section.name}`}
                description={`${section.academic_year}${section.class_teacher ? ` · Homeroom teacher: ${section.class_teacher}` : ''}`}
                back={<BackLink href={route('academic-structure.index')}>Back to Classes</BackLink>}
                actions={(
                    <div className="flex flex-wrap gap-2">
                        <Button variant="secondary" as={Link} href={classCodesUrl} icon={IdentificationIcon}>Report card codes</Button>
                        {canExport && (
                            <Button variant="secondary" as="a" href={route('sections.ranking.export', section.id)} icon={DocumentArrowDownIcon}>Export to Excel</Button>
                        )}
                    </div>
                )}
            >
                <Card>
                    <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
                        <h3 className="text-lg font-semibold text-stone-900 dark:text-stone-100">Required subjects for this class</h3>
                        {canManageRequiredSubjects && !editingSubjects && (
                            <Button size="md" variant="secondary" onClick={() => setEditingSubjects(true)}>
                                {requiredSubjects.length ? 'Change subjects' : 'Set required subjects'}
                            </Button>
                        )}
                    </div>

                    {!editingSubjects && (
                        requiredSubjects.length > 0 ? (
                            <p className="text-base text-stone-700 dark:text-stone-300">
                                Every student is ranked against: <strong>{requiredSubjects.map((s) => s.name).join(', ')}</strong>.
                            </p>
                        ) : (
                            <p className="text-base text-amber-800 dark:text-amber-300">
                                No one has picked the required subjects for this class yet. Using whatever has been published so far instead — set the list explicitly so ranking stays fair once every subject is in.
                            </p>
                        )
                    )}

                    {editingSubjects && (
                        <div className="space-y-4">
                            <p className="text-base text-stone-700 dark:text-stone-300">
                                Pick every subject a student must have a complete, published score in before they can be ranked. A student missing any one of these shows no rank and no average — only what's still missing.
                            </p>
                            <div className="grid gap-2 sm:grid-cols-2">
                                {allSubjects.map((subj) => (
                                    <CheckRow
                                        key={subj.id}
                                        label={subj.name}
                                        checked={data.subject_ids.includes(subj.id)}
                                        onChange={(checked) => toggle(subj.id, checked)}
                                    />
                                ))}
                            </div>
                            <div className="flex gap-3">
                                <Button busy={processing} onClick={save}>Save required subjects</Button>
                                <Button variant="secondary" onClick={() => setEditingSubjects(false)}>Cancel</Button>
                            </div>
                        </div>
                    )}
                </Card>

                <Card className="mt-6">
                    <div className="mb-4 flex flex-wrap items-center gap-3">
                        <TrophyIcon className="h-6 w-6 text-brand-600 dark:text-brand-400" aria-hidden="true" />
                        <h3 className="text-lg font-semibold text-stone-900 dark:text-stone-100">Class list</h3>
                        <StatusBadge tone="success" label={`${roster.complete_count} ranked`} />
                        {roster.incomplete_count > 0 && <StatusBadge tone="warning" label={`${roster.incomplete_count} not ranked yet`} />}
                    </div>

                    {roster.students.length === 0 ? (
                        <EmptyState icon={ArchiveBoxIcon} title="No students in this class yet" />
                    ) : (
                        <ul className="divide-y divide-stone-200 dark:divide-stone-700">
                            {roster.students.map((s) => (
                                <li key={s.enrollment_id} className="flex flex-wrap items-center justify-between gap-3 py-3">
                                    <div className="min-w-0">
                                        <p className="flex items-center gap-2 text-base font-semibold text-stone-900 dark:text-stone-100">
                                            {s.complete && <span className="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-brand-100 text-sm font-bold text-brand-800 dark:bg-brand-900/40 dark:text-brand-300">{s.rank}</span>}
                                            {s.name}
                                        </p>
                                        <p className="text-sm text-stone-500 dark:text-stone-400">{s.student_id_number}</p>
                                        {!s.complete && s.problems.length > 0 && (
                                            <ul className="mt-1 list-disc pl-5 text-sm text-amber-800 dark:text-amber-300">
                                                {s.problems.map((p, i) => <li key={i}>{p}</li>)}
                                            </ul>
                                        )}
                                    </div>
                                    <div className="text-right">
                                        {s.complete ? (
                                            <>
                                                <p className="text-xl font-bold text-stone-900 dark:text-stone-100">{s.overall_average}%</p>
                                                <StatusBadge tone="success" label="Ranked" />
                                            </>
                                        ) : (
                                            <StatusBadge tone="warning" label="Not ranked — incomplete" />
                                        )}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>
            </Page>
        </AuthenticatedLayout>
    );
}
