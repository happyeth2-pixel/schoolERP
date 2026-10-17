import RejectLeaveDialog from '@/Components/RejectLeaveDialog';
import { Button, Card, EmptyState, FlashMessages, Page } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { CalendarIcon } from '@heroicons/react/24/outline';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

export default function Pending({ requests }) {
    const { t } = useTranslation();
    const [rejecting, setRejecting] = useState(null);

    const approve = (r) => router.post(route('leave-requests.approve', r.id), {}, { preserveScroll: true });

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">{t('leave.pending_title')}</h2>}>
            <Page title={t('leave.pending_title')} description={t('leave.pending_desc')}>
                <FlashMessages />

                {requests.length === 0 ? (
                    <Card><EmptyState icon={CalendarIcon} title={t('leave.no_pending_title')}>{t('leave.no_pending_body')}</EmptyState></Card>
                ) : (
                    <ul className="space-y-4" data-testid="leave-pending-list">
                        {requests.map((r) => (
                            <li key={r.id} className="rounded-2xl border-2 border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-800" data-testid="leave-pending-row">
                                <p className="text-lg font-semibold text-gray-900 dark:text-gray-100">{r.employee_name} — {r.leave_type}</p>
                                <p className="text-base text-gray-800 dark:text-gray-200">{t('leave.dated_x', { start: r.start_date, end: r.end_date, count: r.days_count })}</p>
                                <p className="text-sm text-gray-600 dark:text-gray-400">{t('leave.requested_by_x', { name: r.requested_by })}</p>
                                <p className="mt-2 whitespace-pre-wrap text-base text-gray-800 dark:text-gray-200">{r.reason}</p>
                                {r.has_document && (
                                    <a href={route('leave-requests.document', r.id)} target="_blank" rel="noreferrer" className="text-sm font-medium text-indigo-600 hover:underline dark:text-indigo-400">
                                        {t('leave.view_document')}
                                    </a>
                                )}
                                {r.can_review ? (
                                    <div className="mt-4 flex gap-3">
                                        <Button onClick={() => approve(r)}>{t('leave.approve')}</Button>
                                        <Button variant="secondary" onClick={() => setRejecting(r)}>{t('leave.reject')}</Button>
                                    </div>
                                ) : (
                                    <p className="mt-3 text-sm font-medium text-gray-600 dark:text-gray-400" data-testid="own-request-note">{t('leave.cannot_review_own')}</p>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </Page>
            <RejectLeaveDialog key={rejecting?.id ?? 'none'} open={rejecting !== null} request={rejecting} onClose={() => setRejecting(null)} />
        </AuthenticatedLayout>
    );
}
