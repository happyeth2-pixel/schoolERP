import DateText from '@/Components/DateText';
import { Card, EmptyState, Page, Select, StatusBadge } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { ExclamationTriangleIcon } from '@heroicons/react/24/outline';
import { Link, router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

const STATUS_TONE = { open: 'warning', under_review: 'info', resolved: 'success' };

// The school-wide triage view: every incident across every student, filterable by status. Editing a
// specific incident happens on that student's own discipline page (Discipline/ForStudent.jsx) -- this page
// is for finding what needs attention, not for data entry.
export default function Index({ incidents, status }) {
    const { t } = useTranslation();

    const filter = (value) => router.get(route('discipline.index'), value ? { status: value } : {}, { preserveState: true });

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">{t('discipline.dashboard_title')}</h2>}>
            <Page title={t('discipline.dashboard_title')} description={t('discipline.dashboard_desc')} width="default">
                <Card>
                    <Select data-testid="status-filter" value={status ?? ''} onChange={(e) => filter(e.target.value)}>
                        <option value="">{t('discipline.all_statuses')}</option>
                        <option value="open">{t('discipline.status_open')}</option>
                        <option value="under_review">{t('discipline.status_under_review')}</option>
                        <option value="resolved">{t('discipline.status_resolved')}</option>
                    </Select>
                </Card>

                {incidents.length === 0 ? (
                    <Card><EmptyState icon={ExclamationTriangleIcon} title={t('discipline.none_title')}>{t('discipline.dashboard_none_body')}</EmptyState></Card>
                ) : (
                    <div className="space-y-3">
                        {incidents.map((i) => (
                            <Link key={i.id} href={route('discipline.for-student', i.student_id)} data-testid="dashboard-incident-row" className="block rounded-2xl border-2 border-gray-200 bg-white p-5 hover:border-indigo-400 dark:border-gray-700 dark:bg-gray-800">
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <div>
                                        <p className="text-base font-semibold text-gray-900 dark:text-gray-100">{i.student_name} · {t(`discipline.category_${i.category}`)}</p>
                                        <p className="text-sm text-gray-600 dark:text-gray-400"><DateText value={i.incident_date} /> · {t('discipline.reported_by_x', { name: i.reported_by })}</p>
                                    </div>
                                    <StatusBadge tone={STATUS_TONE[i.status]} label={t(`discipline.status_${i.status}`)} />
                                </div>
                            </Link>
                        ))}
                    </div>
                )}
            </Page>
        </AuthenticatedLayout>
    );
}
