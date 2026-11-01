import { BackLink, Button, Card, EmptyState, Page, StatusBadge } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PencilSquareIcon } from '@heroicons/react/24/outline';
import { Link, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

// "What do I need to enter?": one card per class and subject the teacher teaches, an exam per row with where
// its marks are (not started / draft / waiting for approval / approved / published), how many students have a
// mark, and one clearly-named button. Same rows and routes as before; the progress numbers are new.

function ExamRow({ row }) {
    const { t } = useTranslation();
    const href = route('marks.show', [row.exam_id, row.section_id, row.subject_id]);
    const editable = row.status === 'not_started' || row.status === 'draft';
    const label = row.status === 'not_started' ? t('marks.btn_start') : row.status === 'draft' ? t('marks.btn_continue') : t('marks.btn_view');

    return (
        <li className="flex flex-col gap-3 py-4 sm:flex-row sm:items-center sm:justify-between" data-testid="exam-row" data-status={row.status}>
            <div className="min-w-0">
                <p className="text-lg font-semibold text-stone-900 dark:text-stone-100">{row.exam_name}</p>
                <div className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1">
                    <StatusBadge status={row.status} />
                    {row.students > 0 && <span className="text-base text-stone-700 dark:text-stone-300">{t('marks.entered_progress', { entered: row.entered, count: row.students })}</span>}
                </div>
            </div>
            <Button as={Link} href={href} variant={editable ? 'primary' : 'secondary'} icon={editable ? PencilSquareIcon : undefined}>{label}</Button>
        </li>
    );
}

export default function Index({ rows }) {
    const { t } = useTranslation();
    const { auth } = usePage().props;
    const isTeacher = auth.role === 'teacher';

    const groups = [];
    rows.forEach((r) => {
        const key = `${r.section_id}-${r.subject_id}`;
        let g = groups.find((x) => x.key === key);
        if (!g) { g = { key, section: r.section_label, subject: r.subject_name, rows: [] }; groups.push(g); }
        g.rows.push(r);
    });

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">{t('marks.title_enter')}</h2>}>
            <Page
                title={t('marks.title_enter')}
                description={t('marks.index_desc')}
                width="default"
                back={<BackLink big={isTeacher} href={route('dashboard')}>{t('common.back')}</BackLink>}
            >
                {groups.length === 0 && (
                    <Card><EmptyState icon={PencilSquareIcon} title={t('marks.index_empty_title')}>{t('marks.index_empty_body')}</EmptyState></Card>
                )}
                {groups.map((g) => (
                    <Card key={g.key} as="section" aria-label={`${g.section} ${g.subject}`} data-testid="marks-card">
                        <h2 className="text-xl font-bold text-stone-900 dark:text-stone-100">{g.section} · {g.subject}</h2>
                        <ul className="mt-2 divide-y divide-stone-200 dark:divide-stone-700">
                            {g.rows.map((r) => <ExamRow key={r.exam_id} row={r} />)}
                        </ul>
                    </Card>
                ))}
            </Page>
        </AuthenticatedLayout>
    );
}
