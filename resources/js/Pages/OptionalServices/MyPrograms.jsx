import { BackLink, Card, EmptyState, Page } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { AcademicCapIcon } from '@heroicons/react/24/outline';
import { usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

export default function MyPrograms({ programs }) {
    const { t } = useTranslation();
    const { auth } = usePage().props;

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">{t('svc.my_programs_title')}</h2>}>
            <Page title={t('svc.my_programs_title')} description={t('svc.my_programs_desc')} back={<BackLink big={auth.role === 'teacher'} href={route('dashboard')}>{t('common.back')}</BackLink>}>
                {programs.length === 0 ? (
                    <Card><EmptyState icon={AcademicCapIcon} title={t('svc.no_programs_title')}>{t('svc.no_programs_body')}</EmptyState></Card>
                ) : (
                    <ul className="grid gap-4 lg:grid-cols-2" data-testid="my-programs">
                        {programs.map((program) => (
                            <li key={program.id}>
                                <Card data-testid="my-program-card" className="h-full">
                                    <p className="text-xl font-bold text-stone-900 dark:text-stone-100">{program.name}</p>
                                    <p className="text-base text-stone-600 dark:text-stone-400">
                                        {program.period ? `${program.period}${program.period_time ? ` · ${program.period_time}` : ''}` : t('svc.schedule_none')}
                                    </p>
                                    <p className="text-base text-stone-600 dark:text-stone-400">
                                        {program.days_of_week.length > 0
                                            ? program.days_of_week.map((d) => t(`svc.day_${d}`)).join(', ')
                                            : t('svc.no_days_set')}
                                    </p>
                                    <p className="mt-3 text-base font-semibold text-stone-900 dark:text-stone-100">
                                        {t(program.students.length === 1 ? 'svc.program_students_one' : 'svc.program_students_other', { count: program.students.length })}
                                    </p>
                                    {program.students.length === 0 ? (
                                        <p className="mt-2 text-base text-stone-600 dark:text-stone-400">{t('svc.no_students_yet')}</p>
                                    ) : (
                                        <ul className="mt-2 space-y-1" data-testid="my-program-students">
                                            {program.students.map((student) => (
                                                <li key={student.id} className="text-base text-stone-700 dark:text-stone-300">{student.name}</li>
                                            ))}
                                        </ul>
                                    )}
                                </Card>
                            </li>
                        ))}
                    </ul>
                )}
            </Page>
        </AuthenticatedLayout>
    );
}
