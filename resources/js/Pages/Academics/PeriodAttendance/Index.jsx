import AttendanceStatusBadge from '@/Components/AttendanceStatusBadge';
import DateInput from '@/Components/DateInput';
import DateText from '@/Components/DateText';
import { Alert, BackLink, Button, Card, EmptyState, Field, Page, Select } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { CalendarDaysIcon, ClipboardDocumentCheckIcon } from '@heroicons/react/24/outline';
import { Link, router, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

// Step 1 of taking period attendance: which class period, today (or another day)? One card per scheduled
// slot -- unlike daily/homeroom attendance, a teacher can see SEVERAL cards in one day, one per subject.

function SlotCard({ slot, date }) {
    const { t } = useTranslation();
    const href = route('period-attendance.show', slot.id) + `?date=${date}`;
    const label = { none: t('attendance.card_take'), partial: t('attendance.card_continue'), complete: t('attendance.card_review'), empty: t('attendance.card_take') }[slot.status];

    return (
        <Card as="section" aria-label={`${slot.subject} -- ${slot.section}`} data-testid="period-slot-card" data-status={slot.status}>
            <div className="mb-3 flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <h2 className="text-xl font-bold text-stone-900 dark:text-stone-100">{slot.subject}</h2>
                    <p className="mt-1 text-base text-stone-600 dark:text-stone-400">{slot.period_label} &middot; {slot.section}</p>
                    <p className="text-sm text-stone-600 dark:text-stone-400">{t('period_attendance.taught_by', { name: slot.teacher })}</p>
                </div>
                <AttendanceStatusBadge status={slot.status} className="!text-sm" />
            </div>

            {slot.roster_count === 0 ? (
                <p className="text-base text-stone-600 dark:text-stone-400">{t('attendance.no_students_enrolled')}</p>
            ) : (
                <>
                    <p className="mb-3 text-sm text-stone-600 dark:text-stone-400">{t('period_attendance.marked_of', { marked: slot.marked_count, total: slot.roster_count })}</p>
                    <Button as={Link} href={href} variant={slot.status === 'complete' ? 'secondary' : 'primary'} icon={ClipboardDocumentCheckIcon} fullWidth>
                        {label}
                    </Button>
                </>
            )}
        </Card>
    );
}

export default function Index({ date, today, teachers, teacherId, slots }) {
    const { t } = useTranslation();
    const { auth } = usePage().props;
    const isTeacher = auth.role === 'teacher';
    const showPicker = teachers.length > 0;

    const navigate = (next) => router.get(route('period-attendance.index'), { teacher_id: teacherId, date, ...next }, { preserveState: true });

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">{t('period_attendance.title')}</h2>}>
            <Page title={t('period_attendance.title')} description={<>{t('period_attendance.desc')} <DateText value={date} /></>} width="default" back={<BackLink big={isTeacher} href={route('dashboard')}>{t('common.back')}</BackLink>}>
                <Card>
                    <div className={`grid gap-4 ${showPicker ? 'sm:grid-cols-2' : ''}`}>
                        {showPicker && (
                            <Field id="pa_teacher" label={t('period_attendance.teacher_label')}>
                                <Select data-testid="teacher-select" id="pa_teacher" value={teacherId ?? ''} onChange={(e) => navigate({ teacher_id: e.target.value })}>
                                    <option value="" disabled>{t('timetable.pick_option')}</option>
                                    {teachers.map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}
                                </Select>
                            </Field>
                        )}
                        <Field id="pa_date" label={t('period_attendance.date_label')}>
                            <DateInput large value={date} onChange={(next) => navigate({ date: next })} />
                        </Field>
                    </div>
                    {date !== today && <Alert tone="warning" className="mt-4" title={t('attendance.other_day_title')}><DateText value={date} /></Alert>}
                </Card>

                {showPicker && !teacherId ? (
                    <Card><EmptyState icon={CalendarDaysIcon} title={t('period_attendance.pick_teacher_title')}>{t('period_attendance.pick_teacher_body')}</EmptyState></Card>
                ) : slots.length === 0 ? (
                    <Card><EmptyState icon={CalendarDaysIcon} title={t('period_attendance.no_periods_title')}>{t('period_attendance.no_periods_body')}</EmptyState></Card>
                ) : (
                    <div className="grid gap-4 sm:grid-cols-2">
                        {slots.map((s) => <SlotCard key={s.id} slot={s} date={date} />)}
                    </div>
                )}
            </Page>
        </AuthenticatedLayout>
    );
}
