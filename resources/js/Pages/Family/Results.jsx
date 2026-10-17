import { ChildSwitcher } from '@/Components/ParentParts';
import { Card, EmptyState, Page } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { AcademicCapIcon, UserGroupIcon } from '@heroicons/react/24/outline';
import { router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

// A child's published exam scores, as they land through the term -- never waiting for the full report
// card, and never an unpublished/draft mark. Every term they have ever been enrolled in, newest first.

export default function Results({ children, student, terms }) {
    const { t } = useTranslation();
    const go = (params) => router.get(route('family.results'), params, { preserveScroll: true });
    const title = t('results.page_title');

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">{title}</h2>}>
            <Page title={title} description={t('results.page_desc')} width="default">
                {children.length === 0 && (
                    <Card><EmptyState icon={UserGroupIcon} title={t('parent.no_child_title')}>{t('parent.no_child_body')}</EmptyState></Card>
                )}

                {children.length > 0 && <ChildSwitcher children={children} value={student?.id} onChange={(id) => go({ student: id })} />}

                {student && (
                    terms.length === 0 ? (
                        <Card><EmptyState icon={AcademicCapIcon} title={t('results.none_title')}>{t('results.none_body')}</EmptyState></Card>
                    ) : (
                        <div className="space-y-4">
                            {terms.map((term) => (
                                <Card key={`${term.year_name}-${term.term_name}`} data-testid="results-term">
                                    <p className="text-lg font-bold text-gray-900 dark:text-gray-100">{term.term_name} &middot; {term.year_name}</p>
                                    <ul className="mt-3 divide-y divide-gray-200 dark:divide-gray-700">
                                        {term.exams.map((e, i) => (
                                            <li key={i} className="flex flex-wrap items-center justify-between gap-2 py-3" data-testid="results-exam-row">
                                                <div>
                                                    <p className="text-base font-semibold text-gray-900 dark:text-gray-100">{e.subject}</p>
                                                    <p className="text-sm text-gray-600 dark:text-gray-400">{e.exam_name}</p>
                                                </div>
                                                <p className="text-xl font-bold text-gray-900 dark:text-gray-100">
                                                    {e.score === null ? t('results.not_entered') : `${e.score} / ${e.max_score}`}
                                                    {e.percentage !== null && <span className="ml-2 text-base font-semibold text-indigo-600 dark:text-indigo-400">{e.percentage}%</span>}
                                                </p>
                                            </li>
                                        ))}
                                    </ul>
                                </Card>
                            ))}
                        </div>
                    )
                )}
            </Page>
        </AuthenticatedLayout>
    );
}
