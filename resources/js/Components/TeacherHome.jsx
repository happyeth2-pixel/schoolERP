import { ActionTile, Alert, Card, EmptyState } from '@/Components/ui';
import {
    AcademicCapIcon,
    BanknotesIcon,
    BellIcon,
    BookOpenIcon,
    CalendarDaysIcon,
    CalendarIcon,
    ClipboardDocumentCheckIcon,
    ClockIcon,
    MegaphoneIcon,
    PencilIcon,
    QuestionMarkCircleIcon,
    StarIcon,
    UserGroupIcon,
} from '@heroicons/react/24/outline';
import { usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

// A teacher's home: giant buttons, nothing else -- same reasoning as a parent's redesign (near-universally
// phone-only, many never having used a PC before). The classes they teach are shown plainly up top (their
// own roster, non-interactive, same shape as a parent's child roster); every action is listed flat below,
// nothing tucked behind a second "more" page. Attendance stays the one case with its own card per
// homeroom, since each needs its own live status, not just a static label.

const ATTENDANCE_LABEL_KEY = { none: 'attendance.home_take', partial: 'attendance.home_continue', complete: 'attendance.home_review', empty: 'attendance.home_take' };

export default function TeacherHome({ stats }) {
    const { t } = useTranslation();
    const { auth } = usePage().props;
    const has = (p) => (auth.permissions ?? []).includes(p);
    const homerooms = stats.homerooms ?? [];
    // teaching_assignments includes a teacher's OWN homerooms too (it's every subject they teach
    // anywhere) -- excluded here so a homeroom never appears twice in the roster, once as "Homeroom"
    // and again as "— Math".
    const homeroomLabels = new Set(homerooms.map((h) => h.label));
    const subjectClasses = (stats.teaching_assignments ?? []).filter((a) => !homeroomLabels.has(a.section));

    if (homerooms.length === 0 && subjectClasses.length === 0) {
        return (
            <div className="space-y-6" data-testid="teacher-home">
                <Card data-testid="no-homeroom"><EmptyState icon={UserGroupIcon} title={t('attendance.no_class_title')}>{t('attendance.no_class_body')}</EmptyState></Card>
            </div>
        );
    }

    return (
        <div className="space-y-6" data-testid="teacher-home">
            <ul className="flex flex-wrap gap-3" data-testid="class-roster">
                {homerooms.map((h) => (
                    <li key={`h-${h.id}`} data-testid="roster-homeroom" className="flex items-center gap-2 rounded-xl border-2 border-stone-300 bg-white px-4 py-2.5 dark:border-stone-600 dark:bg-stone-800">
                        <span className="block text-base font-bold leading-tight text-stone-900 dark:text-stone-100">{h.label}</span>
                        <span className="block text-sm font-semibold leading-tight text-brand-700 dark:text-brand-400">{t('dashboard.my_homeroom')}</span>
                    </li>
                ))}
                {subjectClasses.map((a, i) => (
                    <li key={`a-${i}`} data-testid="roster-subject" className="flex items-center gap-2 rounded-xl border-2 border-stone-300 bg-white px-4 py-2.5 dark:border-stone-600 dark:bg-stone-800">
                        <span className="block text-base font-bold leading-tight text-stone-900 dark:text-stone-100">{a.section}</span>
                        <span className="block text-sm font-semibold leading-tight text-brand-700 dark:text-brand-400">{a.subject}</span>
                    </li>
                ))}
            </ul>

            <div className="grid gap-4 sm:grid-cols-2">
                {has('mark-attendance') && homerooms.filter((h) => h.roster_count > 0).map((h) => (
                    <ActionTile
                        key={`att-${h.id}`}
                        data-testid="take-attendance"
                        data-status={h.attendance_status}
                        icon={ClipboardDocumentCheckIcon}
                        title={`${t(ATTENDANCE_LABEL_KEY[h.attendance_status])} — ${h.label}`}
                        href={route('attendance.show', h.id)}
                        tint="bg-sky-700 text-white"
                    />
                ))}
                {has('mark-attendance') && (
                    <ActionTile icon={ClockIcon} title={t('nav.period_attendance')} href={route('period-attendance.index')} tint="bg-cyan-700 text-white" />
                )}
                {has('enter-marks') && (
                    <ActionTile icon={PencilIcon} title={t('nav.enter_marks')} href={route('marks.index')} tint="bg-violet-700 text-white" />
                )}
                <ActionTile icon={BookOpenIcon} title={t('nav.assignments')} href={route('assignments.index')} tint="bg-teal-700 text-white" />
                <ActionTile icon={CalendarDaysIcon} title={t('nav.my_timetable')} href={route('timetable.mine')} tint="bg-indigo-700 text-white" />
                <ActionTile icon={AcademicCapIcon} title={t('nav.my_after_school')} href={route('optional-services.mine')} tint="bg-fuchsia-700 text-white" />
                {has('parent-messages') && (
                    <ActionTile icon={BellIcon} title={t('nav.messages')} href={route('messages.index')} tint="bg-amber-700 text-white" />
                )}
                <ActionTile icon={MegaphoneIcon} title={t('nav.announcements')} href={route('announcements.index')} tint="bg-orange-700 text-white" />
                <ActionTile icon={CalendarIcon} title={t('nav.calendar')} href={route('calendar.index')} tint="bg-rose-700 text-white" />
                {(has('leave-request') || has('leave-approve')) && (
                    <ActionTile icon={CalendarDaysIcon} title={t('nav.my_leave')} href={route('leave-requests.mine')} tint="bg-pink-700 text-white" />
                )}
                <ActionTile icon={StarIcon} title={t('nav.my_performance')} href={route('performance-reviews.mine')} tint="bg-emerald-700 text-white" />
                <ActionTile icon={BanknotesIcon} title={t('nav.my_payslips')} href={route('payslips.mine')} tint="bg-lime-700 text-white" />
                <ActionTile icon={BookOpenIcon} title={t('nav.my_library_loans')} href={route('library.mine')} tint="bg-stone-600 text-white" />
                <ActionTile icon={QuestionMarkCircleIcon} title={t('nav.help')} href={route('help.index')} tint="bg-stone-500 text-white" />
            </div>

            {subjectClasses.length > 0 && (
                <div data-testid="taught-only-note">
                    <Alert tone="info">{t('attendance.taught_only', { classes: subjectClasses.map((a) => a.section).join(', ') })}</Alert>
                </div>
            )}
        </div>
    );
}
