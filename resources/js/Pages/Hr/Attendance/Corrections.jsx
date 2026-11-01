import { BackLink, Button, Card, EmptyState, FlashMessages, Page } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { ClipboardDocumentCheckIcon } from '@heroicons/react/24/outline';
import { router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

export default function Corrections({ corrections }) {
    const { t } = useTranslation();

    const approve = (c) => router.post(route('staff-attendance-corrections.approve', c.id), {}, { preserveScroll: true });
    const reject = (c) => router.post(route('staff-attendance-corrections.reject', c.id), {}, { preserveScroll: true });

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">{t('staff_attendance.corrections_title')}</h2>}>
            <Page
                title={t('staff_attendance.corrections_title')}
                description={t('staff_attendance.corrections_desc')}
                back={<BackLink href={route('staff-attendance.index')}>{t('staff_attendance.back_to_roster')}</BackLink>}
            >
                <FlashMessages />

                {corrections.length === 0 ? (
                    <Card><EmptyState icon={ClipboardDocumentCheckIcon} title={t('staff_attendance.no_pending_corrections')}>{t('staff_attendance.no_pending_corrections_body')}</EmptyState></Card>
                ) : (
                    <ul className="space-y-4" data-testid="correction-list">
                        {corrections.map((c) => (
                            <li key={c.id} className="rounded-2xl border-2 border-stone-200 bg-white p-5 dark:border-stone-700 dark:bg-stone-800" data-testid="correction-row">
                                <p className="text-lg font-semibold text-stone-900 dark:text-stone-100">{c.employee_name} — {c.date}</p>
                                <p className="mt-1 text-base text-stone-800 dark:text-stone-200">
                                    {t(`staff_attendance.status_${c.current_status}`)} → {t(`staff_attendance.status_${c.new_status}`)}
                                </p>
                                <p className="text-sm text-stone-600 dark:text-stone-400">{t('staff_attendance.requested_by_x', { name: c.requested_by })}</p>
                                <p className="mt-2 whitespace-pre-wrap text-base text-stone-800 dark:text-stone-200">{c.reason}</p>
                                {c.can_review ? (
                                    <div className="mt-4 flex gap-3">
                                        <Button onClick={() => approve(c)}>{t('staff_attendance.approve')}</Button>
                                        <Button variant="secondary" onClick={() => reject(c)}>{t('staff_attendance.reject')}</Button>
                                    </div>
                                ) : (
                                    <p className="mt-3 text-sm font-medium text-stone-600 dark:text-stone-400" data-testid="own-request-note">{t('staff_attendance.cannot_review_own')}</p>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </Page>
        </AuthenticatedLayout>
    );
}
