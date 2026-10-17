import { ChildSwitcher } from '@/Components/ParentParts';
import { Card, EmptyState, Page } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { CalendarDaysIcon, UserGroupIcon } from '@heroicons/react/24/outline';
import { router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

const DAYS = [1, 2, 3, 4, 5, 6, 7];

// A child's weekly class schedule for a parent -- the exact same day x period grid the teacher's own
// "My Timetable" page already uses, just scoped to the child's current section instead of a teacher's slots.

export default function Timetable({ children, student, termName, slots }) {
    const { t } = useTranslation();
    const go = (params) => router.get(route('family.timetable'), params, { preserveScroll: true });
    const title = t('timetable.family_title');
    const periodLabels = [...new Map(slots.map((s) => [s.period_label, s.period_label])).keys()];
    const cellsFor = (periodLabel, day) => slots.filter((s) => s.period_label === periodLabel && s.day_of_week === day);

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">{title}</h2>}>
            <Page title={title} description={termName ? t('timetable.my_desc_x', { term: termName }) : t('timetable.my_desc_no_term')} width="default">
                {children.length === 0 && (
                    <Card><EmptyState icon={UserGroupIcon} title={t('parent.no_child_title')}>{t('parent.no_child_body')}</EmptyState></Card>
                )}

                {children.length > 0 && <ChildSwitcher children={children} value={student?.id} onChange={(id) => go({ student: id })} />}

                {student && (
                    slots.length === 0 ? (
                        <Card><EmptyState icon={CalendarDaysIcon} title={t('timetable.no_slots_title')}>{t('timetable.no_slots_body')}</EmptyState></Card>
                    ) : (
                        <Card>
                            <div className="overflow-x-auto">
                                <table className="w-full border-collapse text-left text-sm" data-testid="family-timetable-grid">
                                    <thead>
                                        <tr>
                                            <th className="border border-gray-200 p-2 dark:border-gray-700" />
                                            {DAYS.map((d) => (
                                                <th key={d} className="border border-gray-200 p-2 text-gray-700 dark:border-gray-700 dark:text-gray-300">{t(`timetable.day_${d}`)}</th>
                                            ))}
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {periodLabels.map((label) => (
                                            <tr key={label}>
                                                <td className="border border-gray-200 p-2 font-medium text-gray-700 dark:border-gray-700 dark:text-gray-300">{label}</td>
                                                {DAYS.map((d) => (
                                                    <td key={d} className="border border-gray-200 p-2 align-top dark:border-gray-700" data-testid="family-timetable-cell">
                                                        {cellsFor(label, d).map((cell) => (
                                                            <div key={cell.id} data-testid="family-timetable-slot" className="mb-2 last:mb-0">
                                                                <p className="font-semibold text-gray-900 dark:text-gray-100">{cell.subject}</p>
                                                                <p className="text-xs text-gray-600 dark:text-gray-400">{cell.teacher} &middot; {cell.room ?? t('timetable.no_room')}</p>
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
                    )
                )}
            </Page>
        </AuthenticatedLayout>
    );
}
