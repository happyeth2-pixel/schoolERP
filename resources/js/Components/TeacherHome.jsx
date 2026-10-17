import { Alert, Button, Card, EmptyState } from '@/Components/ui';
import { MarkProgress } from '@/Components/AttendanceParts';
import { greetingKey } from '@/greeting';
import { ClipboardDocumentCheckIcon, UsersIcon } from '@heroicons/react/24/outline';
import { Link, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

// A teacher's home starts with THEIR class: "Good morning, <name>", "Your class: Grade 3 - Section A", how many
// students, today's attendance in words, and one button that goes straight to marking. The system already knows
// which class is theirs (they are its class teacher), so nobody is asked to choose. Attendance stays the class
// teacher's job: a subject teacher who is not a class teacher gets a plain explanation, never the button.
// Only ever links to routes the server already authorises (attendance.show refuses anyone else).

export default function TeacherHome({ stats }) {
    const { t } = useTranslation();
    const { auth, today } = usePage().props;
    const rooms = stats.homerooms ?? [];
    const greeting = t(greetingKey(today?.hour ?? 12), { name: auth.user.name });

    return (
        <section aria-labelledby="teacher-greeting" data-testid="teacher-home" className="space-y-5">
            <h1 id="teacher-greeting" className="text-2xl font-bold text-gray-900 dark:text-gray-100 sm:text-3xl" data-testid="greeting">{greeting}</h1>

            {rooms.length === 0 ? (
                <Card data-testid="no-homeroom">
                    <EmptyState icon={ClipboardDocumentCheckIcon} title={t('attendance.no_class_title')}>{t('attendance.no_class_body')}</EmptyState>
                    {(stats.taught_only ?? []).length > 0 && <div className="mt-4"><Alert tone="info">{t('attendance.taught_only', { classes: stats.taught_only.join(', ') })}</Alert></div>}
                </Card>
            ) : (
                <div>
                    <p className="mb-3 text-xl text-gray-700 dark:text-gray-300" data-testid="your-class">{rooms.length === 1 ? t('attendance.home_your_class') : t('attendance.home_your_classes')}</p>
                    <div className={`grid gap-4 ${rooms.length > 1 ? 'lg:grid-cols-2' : ''}`}>
                        {rooms.map((h) => {
                            const label = { none: t('attendance.home_take'), partial: t('attendance.home_continue'), complete: t('attendance.home_review'), empty: t('attendance.home_take') }[h.attendance_status];
                            return (
                                <Card key={h.id} data-testid="homeroom-card" data-status={h.attendance_status}>
                                    <h2 className="text-2xl font-bold text-gray-900 dark:text-gray-100" data-testid="homeroom-class">{t('attendance.class_line', { grade: h.grade, section: h.section_name })}</h2>
                                    <p className="mt-1 flex items-center gap-2 text-lg text-gray-700 dark:text-gray-300" data-testid="homeroom-count">
                                        <UsersIcon className="h-6 w-6" aria-hidden="true" /> {t('attendance.card_students', { count: h.roster_count })}
                                    </p>
                                    <h3 className="mt-4 text-lg font-semibold text-gray-900 dark:text-gray-100">{t('attendance.home_today_att')}</h3>
                                    {h.roster_count > 0 ? (
                                        <div className="mt-2" data-testid="homeroom-progress"><MarkProgress marked={h.marked_count} total={h.roster_count} short /></div>
                                    ) : (
                                        <p className="mt-2 text-base text-gray-700 dark:text-gray-300">{t('attendance.no_students_enrolled')}</p>
                                    )}
                                    {h.roster_count > 0 && (
                                        <Button as={Link} href={route('attendance.show', h.id)} icon={ClipboardDocumentCheckIcon} fullWidth variant={h.attendance_status === 'complete' ? 'secondary' : 'primary'} className="mt-5" data-testid="take-attendance">{label}</Button>
                                    )}
                                </Card>
                            );
                        })}
                    </div>
                    {(stats.taught_only ?? []).length > 0 && <div className="mt-4"><Alert tone="info">{t('attendance.taught_only', { classes: stats.taught_only.join(', ') })}</Alert></div>}
                </div>
            )}
        </section>
    );
}
