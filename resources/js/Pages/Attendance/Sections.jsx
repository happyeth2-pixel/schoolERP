import AttendanceStatusBadge from '@/Components/AttendanceStatusBadge';
import { MarkProgress } from '@/Components/AttendanceParts';
import DateText from '@/Components/DateText';
import { Alert, Button, Card, EmptyState, Page } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { ClipboardDocumentCheckIcon, UsersIcon } from '@heroicons/react/24/outline';
import { Link } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

// Step 1 of taking attendance: which class? One large card per class, showing today's progress in words
// ("23 of 30 students marked") and one clearly-named button. A teacher normally has one card.

function SectionCard({ section, date }) {
    const { t } = useTranslation();
    const href = route('attendance.show', section.id) + `?date=${date}`;
    const label = { none: t('attendance.card_take'), partial: t('attendance.card_continue'), complete: t('attendance.card_review'), empty: t('attendance.card_take') }[section.attendance_status];

    return (
        <Card as="section" aria-label={section.label} data-testid="class-card" data-status={section.attendance_status}>
            <div className="mb-4 flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <h2 className="text-xl font-bold text-gray-900 dark:text-gray-100">{section.label}</h2>
                    <p className="mt-1 flex items-center gap-1.5 text-base text-gray-600 dark:text-gray-400">
                        <UsersIcon className="h-5 w-5" aria-hidden="true" /> {t('attendance.card_students', { count: section.roster_count })}
                    </p>
                </div>
                <AttendanceStatusBadge status={section.attendance_status} className="!text-sm" />
            </div>

            {section.roster_count > 0 && <MarkProgress marked={section.marked_count} total={section.roster_count} />}
            {section.last_marked_by && (
                <p className="mt-3 text-sm text-gray-600 dark:text-gray-400">{t('attendance.card_last', { name: section.last_marked_by, when: section.last_marked_at })}</p>
            )}

            <div className="mt-5">
                {section.roster_count === 0 ? (
                    <p className="text-base text-gray-600 dark:text-gray-400">{t('attendance.no_students_enrolled')}</p>
                ) : (
                    <Button as={Link} href={href} variant={section.attendance_status === 'complete' ? 'secondary' : 'primary'} icon={ClipboardDocumentCheckIcon} fullWidth>
                        {label}
                    </Button>
                )}
            </div>
        </Card>
    );
}

export default function Sections({ sections, date, taughtOnly = [] }) {
    const { t } = useTranslation();

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">{t('attendance.choose_title')}</h2>}>
            <Page title={t('attendance.choose_title')} description={<>{t('attendance.choose_today')} <DateText value={date} />. {t('attendance.choose_desc')}</>} width="default">
                {sections.length === 0 && (
                    <Card>
                        <EmptyState icon={ClipboardDocumentCheckIcon} title={t('attendance.no_class_title')}>{t('attendance.no_class_body')}</EmptyState>
                        {taughtOnly.length > 0 && <Alert tone="info">{t('attendance.taught_only', { classes: taughtOnly.join(', ') })}</Alert>}
                    </Card>
                )}

                <div className="grid gap-4 sm:grid-cols-2">
                    {sections.map((s) => (
                        <SectionCard key={s.id} section={s} date={date} />
                    ))}
                </div>
            </Page>
        </AuthenticatedLayout>
    );
}
