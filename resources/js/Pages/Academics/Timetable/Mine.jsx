import { BackLink, Card, EmptyState, Page } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { CalendarDaysIcon } from '@heroicons/react/24/outline';
import { usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

const DAYS = [1, 2, 3, 4, 5, 6, 7];

export default function Mine({ termName, slots }) {
    const { t } = useTranslation();
    const { auth } = usePage().props;
    const isTeacher = auth.role === 'teacher';
    const periodLabels = [...new Map(slots.map((s) => [s.period_label, s.period_label])).keys()];
    const cellsFor = (periodLabel, day) => slots.filter((s) => s.period_label === periodLabel && s.day_of_week === day);

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">{t('timetable.my_title')}</h2>}>
            <Page title={t('timetable.my_title')} description={termName ? t('timetable.my_desc_x', { term: termName }) : t('timetable.my_desc_no_term')} back={<BackLink big={isTeacher} href={route('dashboard')}>{t('common.back')}</BackLink>}>
                {slots.length === 0 ? (
                    <Card><EmptyState icon={CalendarDaysIcon} title={t('timetable.no_slots_title')}>{t('timetable.no_slots_body')}</EmptyState></Card>
                ) : (
                    <Card>
                        <div className="overflow-x-auto">
                            <table className="w-full border-collapse text-left text-sm" data-testid="my-timetable-grid">
                                <thead>
                                    <tr>
                                        <th className="border border-stone-200 p-2 dark:border-stone-700" />
                                        {DAYS.map((d) => (
                                            <th key={d} className="border border-stone-200 p-2 text-stone-700 dark:border-stone-700 dark:text-stone-300">{t(`timetable.day_${d}`)}</th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {periodLabels.map((label) => (
                                        <tr key={label}>
                                            <td className="border border-stone-200 p-2 font-medium text-stone-700 dark:border-stone-700 dark:text-stone-300">{label}</td>
                                            {DAYS.map((d) => (
                                                <td key={d} className="border border-stone-200 p-2 align-top dark:border-stone-700" data-testid="my-timetable-cell">
                                                    {cellsFor(label, d).map((cell) => (
                                                        // An authorized override can double-book this teacher on purpose (covering a
                                                        // gap, a substitution, etc) -- show every slot in the cell, not just the first,
                                                        // or the teacher never finds out about the second commitment at all.
                                                        <div key={cell.id} data-testid="my-timetable-slot" className="mb-2 last:mb-0">
                                                            <p className="font-semibold text-stone-900 dark:text-stone-100">{cell.subject}</p>
                                                            <p className="text-xs text-stone-600 dark:text-stone-400">{cell.section} &middot; {cell.room ?? t('timetable.no_room')}</p>
                                                        </div>
                                                    ))}
                                                </td>
                                            ))}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </Card>
                )}
            </Page>
        </AuthenticatedLayout>
    );
}
