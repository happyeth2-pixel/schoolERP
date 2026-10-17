import DateText from '@/Components/DateText';
import { Card, EmptyState, Page, StatusBadge } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { BookOpenIcon } from '@heroicons/react/24/outline';
import { useTranslation } from 'react-i18next';

// Any staff account's OWN library history -- no permission needed, the same self-service shape as My
// Leave/My Performance/My Payslips/My Timetable. Students have no login, so this is staff-only.
export default function Mine({ loans }) {
    const { t } = useTranslation();

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">{t('library.my_title')}</h2>}>
            <Page title={t('library.my_title')} description={t('library.my_desc')} width="default">
                {loans.length === 0 ? (
                    <Card><EmptyState icon={BookOpenIcon} title={t('library.my_none_title')}>{t('library.my_none_body')}</EmptyState></Card>
                ) : (
                    <div className="space-y-3">
                        {loans.map((l) => (
                            <Card key={l.id} data-testid="my-loan-row">
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <div>
                                        <p className="text-base font-semibold text-gray-900 dark:text-gray-100">{l.book_title}</p>
                                        <p className="text-sm text-gray-600 dark:text-gray-400">
                                            {l.returned_at ? t('library.my_returned_label') : t('library.my_due_label')} <DateText value={l.returned_at ?? l.due_date} />
                                        </p>
                                        {l.fine_amount && (
                                            <p className="text-sm text-gray-700 dark:text-gray-300">
                                                {l.fine_resolved ? t('library.my_fine_resolved', { amount: l.fine_amount }) : t('library.my_fine_owed', { amount: l.fine_amount })}
                                            </p>
                                        )}
                                    </div>
                                    <div className="flex items-center gap-2">
                                        {l.overdue && <StatusBadge tone="danger" label={t('library.overdue_badge')} />}
                                        <StatusBadge tone={l.status === 'active' ? 'info' : l.status === 'returned' ? 'success' : 'warning'} label={t(`library.status_${l.status === 'active' ? 'on_loan' : l.status}`)} />
                                    </div>
                                </div>
                            </Card>
                        ))}
                    </div>
                )}
            </Page>
        </AuthenticatedLayout>
    );
}
