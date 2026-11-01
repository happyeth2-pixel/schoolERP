import { BackLink, Card, EmptyState, Page, SectionHeader } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { StarIcon } from '@heroicons/react/24/outline';
import { usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

export default function Mine({ employee, reviews }) {
    const { t } = useTranslation();
    const { auth } = usePage().props;

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">{t('performance.my_title')}</h2>}>
            <Page
                title={t('performance.my_title')}
                description={t('performance.my_desc')}
                back={<BackLink big={auth.role === 'teacher'} href={route('dashboard')}>{t('common.back')}</BackLink>}
            >
                {!employee ? (
                    <Card><EmptyState icon={StarIcon} title={t('performance.no_employee_record_title')}>{t('performance.no_employee_record_body')}</EmptyState></Card>
                ) : (
                    <Card>
                        <SectionHeader as="h3" title={t('performance.my_reviews_title')} />
                        {reviews.length === 0 ? (
                            <p className="text-base text-stone-600 dark:text-stone-400" data-testid="no-reviews">{t('performance.no_reviews')}</p>
                        ) : (
                            <ul className="divide-y divide-stone-200 dark:divide-stone-700" data-testid="performance-review-list">
                                {reviews.map((r) => (
                                    <li key={r.id} className="py-3" data-testid="performance-review-row">
                                        <p className="text-base font-semibold text-stone-900 dark:text-stone-100">{t(`performance.rating_${r.rating}`)}</p>
                                        <p className="text-sm text-stone-600 dark:text-stone-400">{t('performance.dated_x', { cycle: r.cycle, date: r.finalized_at })}</p>
                                        <p className="mt-2 whitespace-pre-wrap text-base text-stone-800 dark:text-stone-200">{r.comments}</p>
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
