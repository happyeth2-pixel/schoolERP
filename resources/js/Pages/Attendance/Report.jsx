import DateInput from '@/Components/DateInput';
import { BackLink } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

export default function Report({ date, sections, overall }) {
    const { t } = useTranslation();
    const { auth } = usePage().props;
    const changeDate = (newDate) => {
        router.get(route('attendance-report.index'), { date: newDate });
    };

    return (
        <AuthenticatedLayout
            header={<h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">{t('attendance.report_title')}</h2>}
        >
            <Head title={t('attendance.report_title')} />

            <div className="py-8">
                <div className="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8">
                    <BackLink big={auth.role === 'teacher'} href={route('dashboard')}>{t('common.back')}</BackLink>
                    <div className="mb-4 mt-4 flex items-center justify-between">
                        <DateInput value={date} onChange={(v) => v && changeDate(v)} />
                    </div>

                    <div className="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
                        {[
                            [t('attendance.stat_students'), overall.total_students],
                            [t('attendance.stat_marked'), overall.total_marked],
                            [t('attendance.status_present'), overall.total_present],
                            [t('attendance.status_absent'), overall.total_absent],
                        ].map(([label, value]) => (
                            <div key={label} className="rounded-lg bg-white p-4 shadow-sm dark:bg-stone-800">
                                <p className="text-xs text-stone-400">{label}</p>
                                <p className="text-xl font-semibold text-stone-900 dark:text-stone-100">{value}</p>
                            </div>
                        ))}
                    </div>

                    <div className="overflow-hidden rounded-lg bg-white shadow-sm dark:bg-stone-800">
                        <table className="min-w-full divide-y divide-stone-200 dark:divide-stone-700">
                            <thead className="bg-stone-50 dark:bg-stone-900/40">
                                <tr>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-stone-500">{t('attendance.col_section')}</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-stone-500">{t('attendance.col_branch')}</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-stone-500">{t('attendance.stat_marked')}</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-stone-500">{t('attendance.present_absent_late_header')}</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-stone-500">{t('attendance.col_rate')}</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-stone-200 dark:divide-stone-700">
                                {sections.map((s) => (
                                    <tr key={s.id}>
                                        <td className="px-4 py-3 text-sm text-stone-900 dark:text-stone-100">{s.label}</td>
                                        <td className="px-4 py-3 text-sm text-stone-500">{s.branch}</td>
                                        <td className="px-4 py-3 text-sm text-stone-500">{s.marked} / {s.total}</td>
                                        <td className="px-4 py-3 text-sm text-stone-500">
                                            {s.counts.present} / {s.counts.absent} / {s.counts.late}
                                        </td>
                                        <td className="px-4 py-3 text-sm text-stone-500">
                                            {s.attendance_rate !== null ? `${s.attendance_rate}%` : t('attendance.not_marked')}
                                        </td>
                                    </tr>
                                ))}
                                {sections.length === 0 && (
                                    <tr>
                                        <td colSpan={5} className="px-4 py-6 text-center text-sm text-stone-400">{t('attendance.no_sections')}</td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
