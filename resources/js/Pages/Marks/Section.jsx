import { Alert, BackLink, Button, Card, EmptyState, Page, StatusBadge } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PencilSquareIcon, UserGroupIcon } from '@heroicons/react/24/outline';
import { Link } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

// One class, opened from its card on Marks/Index: every subject taught here the person may enter marks
// for -- their own, or (as the homeroom teacher) every one of them -- each as its own card with one row
// per exam.

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

export default function Section({ section, isHomeroom, subjects }) {
    const { t } = useTranslation();

    return (
        <AuthenticatedLayout header={<h2 className="truncate text-lg font-semibold text-stone-800 dark:text-stone-200">{section.label}</h2>}>
            <Page
                title={section.label}
                description={t('marks.section_desc')}
                width="default"
                back={<BackLink href={route('marks.index')}>{t('marks.back_to_marks')}</BackLink>}
            >
                {isHomeroom && (
                    <Alert tone="info" title={t('marks.section_homeroom_title')}>
                        <span className="inline-flex items-start gap-2"><UserGroupIcon className="mt-0.5 h-5 w-5 shrink-0" aria-hidden="true" />{t('marks.section_homeroom_body')}</span>
                    </Alert>
                )}

                {subjects.length === 0 ? (
                    <Card><EmptyState icon={PencilSquareIcon} title={t('marks.index_empty_title')}>{t('marks.index_empty_body')}</EmptyState></Card>
                ) : (
                    subjects.map((s) => (
                        <Card key={s.subject_id} as="section" aria-label={s.subject_name} data-testid="subject-card">
                            <h2 className="text-xl font-bold text-stone-900 dark:text-stone-100">{s.subject_name}</h2>
                            <ul className="mt-2 divide-y divide-stone-200 dark:divide-stone-700">
                                {s.exams.map((row) => <ExamRow key={row.exam_id} row={{ ...row, section_id: section.id, subject_id: s.subject_id }} />)}
                            </ul>
                        </Card>
                    ))
                )}
            </Page>
        </AuthenticatedLayout>
    );
}
