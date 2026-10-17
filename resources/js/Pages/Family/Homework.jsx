import DateText from '@/Components/DateText';
import { ChildSwitcher } from '@/Components/ParentParts';
import { Card, EmptyState, Page } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { BookOpenIcon, PaperClipIcon, UserGroupIcon } from '@heroicons/react/24/outline';
import { router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

// A child's homework for a parent: only ever what their teacher has actually published, never a draft
// in progress. Same child-switcher shape as Attendance/Report Cards.

export default function Homework({ children, student, section, assignments }) {
    const { t } = useTranslation();
    const go = (params) => router.get(route('family.homework'), params, { preserveScroll: true });
    const title = t('homework.page_title');

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">{title}</h2>}>
            <Page title={title} description={t('homework.page_desc')} width="default">
                {children.length === 0 && (
                    <Card><EmptyState icon={UserGroupIcon} title={t('parent.no_child_title')}>{t('parent.no_child_body')}</EmptyState></Card>
                )}

                {children.length > 0 && <ChildSwitcher children={children} value={student?.id} onChange={(id) => go({ student: id })} />}

                {student && (
                    <>
                        {section && <p className="text-base text-gray-700 dark:text-gray-300">{section}</p>}

                        {assignments.length === 0 ? (
                            <Card><EmptyState icon={BookOpenIcon} title={t('homework.none_title')}>{t('homework.none_body')}</EmptyState></Card>
                        ) : (
                            <ul className="space-y-3" data-testid="homework-list">
                                {assignments.map((a) => (
                                    <li key={a.id}>
                                        <Card data-testid="homework-row">
                                            <p className="text-sm font-semibold uppercase tracking-wide text-indigo-600 dark:text-indigo-400">{a.subject_name}</p>
                                            <p className="mt-1 text-lg font-bold text-gray-900 dark:text-gray-100">{a.title}</p>
                                            <p className="text-sm text-gray-600 dark:text-gray-400">{t('homework.due_label')} <DateText value={a.due_date} /> · {a.teacher_name}</p>
                                            {a.description && <p className="mt-2 whitespace-pre-line text-base text-gray-800 dark:text-gray-200">{a.description}</p>}
                                            {a.has_attachment && (
                                                <a href={route('assignments.document', a.id)} target="_blank" rel="noreferrer" className="mt-2 inline-flex items-center gap-1 text-sm font-medium text-indigo-600 hover:underline dark:text-indigo-400">
                                                    <PaperClipIcon className="h-4 w-4" /> {t('homework.view_attachment')}
                                                </a>
                                            )}
                                        </Card>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </>
                )}
            </Page>
        </AuthenticatedLayout>
    );
}
