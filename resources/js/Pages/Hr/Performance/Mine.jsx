import { Card, EmptyState, Page, SectionHeader } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { StarIcon } from '@heroicons/react/24/outline';
import { useTranslation } from 'react-i18next';

export default function Mine({ employee, reviews }) {
    const { t } = useTranslation();

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">{t('performance.my_title')}</h2>}>
            <Page title={t('performance.my_title')} description={t('performance.my_desc')}>
                {!employee ? (
                    <Card><EmptyState icon={StarIcon} title={t('performance.no_employee_record_title')}>{t('performance.no_employee_record_body')}</EmptyState></Card>
                ) : (
                    <Card>
                        <SectionHeader as="h3" title={t('performance.my_reviews_title')} />
                        {reviews.length === 0 ? (
                            <p className="text-base text-gray-600 dark:text-gray-400" data-testid="no-reviews">{t('performance.no_reviews')}</p>
                        ) : (
                            <ul className="divide-y divide-gray-200 dark:divide-gray-700" data-testid="performance-review-list">
                                {reviews.map((r) => (
                                    <li key={r.id} className="py-3" data-testid="performance-review-row">
                                        <p className="text-base font-semibold text-gray-900 dark:text-gray-100">{t(`performance.rating_${r.rating}`)}</p>
                                        <p className="text-sm text-gray-600 dark:text-gray-400">{t('performance.dated_x', { cycle: r.cycle, date: r.finalized_at })}</p>
                                        <p className="mt-2 whitespace-pre-wrap text-base text-gray-800 dark:text-gray-200">{r.comments}</p>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>
                )}
            </Page>
        </AuthenticatedLayout>
    );
}
