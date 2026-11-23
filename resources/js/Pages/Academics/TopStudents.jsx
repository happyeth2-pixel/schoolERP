import { BackLink, Button, Card, EmptyState, Page, StatusBadge, useConfirm } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { DocumentArrowDownIcon, GlobeAltIcon, TrophyIcon } from '@heroicons/react/24/outline';
import { Head, router } from '@inertiajs/react';

const MEDAL = { 1: '🥇', 2: '🥈', 3: '🥉' };

// The principal's/owner's school-wide leaderboard -- every ranked student from every section, best average
// first, so "who actually got the top results in the whole school" has one answer instead of a dozen
// per-class screens. From here, the top 3 of any section can be posted to the public homepage.
export default function TopStudents({ academicYear, students, bySection, canPost, highlights }) {
    const [ask, confirmDialog] = useConfirm();

    const post = async (student, sectionId, year) => {
        const ok = await ask({
            title: `Post ${student.name} to the public homepage?`,
            body: <p>Their name, photo (if any) and rank will be visible to anyone visiting the school website.</p>,
            confirmLabel: 'Post to homepage',
            tone: 'info',
        });
        if (!ok) return;

        router.post(route('top-student-highlights.post'), {
            student_id: student.student_id,
            section_id: sectionId,
            academic_year_id: year,
            rank: student.rank,
            overall_average: student.overall_average,
        }, { preserveScroll: true });
    };

    const unpublish = async (highlight) => {
        const ok = await ask({
            title: `Remove ${highlight.student_name} from the homepage?`,
            body: <p>This only takes it off the public site — their rank and record stay exactly as they are.</p>,
            confirmLabel: 'Remove from homepage',
            tone: 'danger',
        });
        if (ok) router.post(route('top-student-highlights.unpublish', highlight.id), {}, { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">Top Students — Whole School</h2>}>
            <Head title="Top Students" />
            <Page
                title="Top Students — Whole School"
                description={academicYear ? `Every ranked student, best average first — ${academicYear}.` : 'No current academic year is set.'}
                back={<BackLink href={route('dashboard')}>Back to Home</BackLink>}
                actions={<Button variant="secondary" as="a" href={route('top-students.export')} icon={DocumentArrowDownIcon}>Export to Excel</Button>}
            >
                {canPost && highlights.length > 0 && (
                    <Card className="mb-6">
                        <h3 className="mb-3 flex items-center gap-2 text-lg font-semibold text-stone-900 dark:text-stone-100">
                            <GlobeAltIcon className="h-5 w-5 text-brand-600 dark:text-brand-400" /> Currently on the public homepage
                        </h3>
                        <ul className="space-y-2">
                            {highlights.map((h) => (
                                <li key={h.id} className="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-stone-50 p-3 dark:bg-stone-900/40">
                                    <span className="text-base">{MEDAL[h.rank] ?? h.rank} <strong>{h.student_name}</strong> — {h.section}</span>
                                    <Button size="md" variant="quiet" onClick={() => unpublish(h)}>Remove from homepage</Button>
                                </li>
                            ))}
                        </ul>
                    </Card>
                )}

                <Card>
                    <h3 className="mb-4 flex items-center gap-2 text-lg font-semibold text-stone-900 dark:text-stone-100">
                        <TrophyIcon className="h-6 w-6 text-brand-600 dark:text-brand-400" /> School-wide ranking
                    </h3>

                    {students.length === 0 ? (
                        <EmptyState icon={TrophyIcon} title="Nobody is ranked yet" description="Results need to be published, and complete, before anyone shows up here." />
                    ) : (
                        <ul className="divide-y divide-stone-200 dark:divide-stone-700">
                            {students.map((s, i) => (
                                <li key={s.enrollment_id} className="flex flex-wrap items-center justify-between gap-3 py-3">
                                    <div className="flex items-center gap-3">
                                        <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-brand-100 text-base font-bold text-brand-800 dark:bg-brand-900/40 dark:text-brand-300">{i + 1}</span>
                                        <div>
                                            <p className="text-base font-semibold text-stone-900 dark:text-stone-100">{s.name}</p>
                                            <p className="text-sm text-stone-500 dark:text-stone-400">{s.section} · {s.student_id_number}</p>
                                        </div>
                                    </div>
                                    <div className="flex items-center gap-3">
                                        <StatusBadge tone="success" label={`${s.overall_average}%`} />
                                        {canPost && s.rank <= 3 && (
                                            <Button size="md" variant="secondary" onClick={() => post(s, s.section_id, s.academic_year_id)}>Post to homepage</Button>
                                        )}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>
            </Page>
            {confirmDialog}
        </AuthenticatedLayout>
    );
}
