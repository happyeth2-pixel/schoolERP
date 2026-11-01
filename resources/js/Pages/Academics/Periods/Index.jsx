import PeriodDialog from '@/Components/PeriodDialog';
import { BackLink, Button, Card, EmptyState, FlashMessages, Page, StatusBadge, useConfirm } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { ClockIcon, PlusIcon } from '@heroicons/react/24/outline';
import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

const TYPE_TONE = { teaching: 'neutral', break: 'info', lunch: 'warning' };

export default function Index({ periods, types, canManage }) {
    const { t } = useTranslation();
    const { auth } = usePage().props;
    const isTeacher = auth.role === 'teacher';
    const [dialog, setDialog] = useState(null); // null | 'new' | a period object
    const [ask, confirmDialog] = useConfirm();

    const remove = async (period) => {
        if (await ask({
            title: t('timetable.remove_period_title', { name: period.name || `Period ${period.period_number}` }),
            body: t('timetable.remove_period_body'),
            confirmLabel: t('timetable.remove_period_confirm'),
            tone: 'danger',
        })) {
            router.delete(route('periods.destroy', period.id), { preserveScroll: true });
        }
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">{t('timetable.periods_title')}</h2>}>
            <Page
                title={t('timetable.periods_title')}
                description={t('timetable.periods_desc')}
                back={<BackLink big={isTeacher} href={route('dashboard')}>{t('common.back')}</BackLink>}
                actions={canManage && <Button icon={PlusIcon} onClick={() => setDialog('new')}>{t('timetable.add_period_button')}</Button>}
            >
                <FlashMessages errorKeys={['name']} />

                {periods.length === 0 ? (
                    <Card><EmptyState icon={ClockIcon} title={t('timetable.empty_periods_title')}>{t('timetable.empty_periods_body')}</EmptyState></Card>
                ) : (
                    <Card>
                        <ul className="divide-y divide-stone-200 dark:divide-stone-700" data-testid="period-list">
                            {periods.map((p) => (
                                <li key={p.id} className="flex flex-wrap items-center justify-between gap-2 py-3" data-testid="period-row">
                                    <div>
                                        <span className="flex items-center gap-2 text-base font-semibold text-stone-900 dark:text-stone-100">
                                            {p.name || `Period ${p.period_number}`}
                                            {p.type !== 'teaching' && <StatusBadge tone={TYPE_TONE[p.type]} label={t(`timetable.period_type_${p.type}`)} />}
                                        </span>
                                        <p className="text-sm text-stone-600 dark:text-stone-400">{p.start_time} &ndash; {p.end_time}</p>
                                    </div>
                                    {canManage && (
                                        <div className="flex items-center gap-3">
                                            <Button size="sm" variant="quiet" onClick={() => setDialog(p)}>{t('timetable.edit_button')}</Button>
                                            <Button size="sm" variant="quiet" onClick={() => remove(p)}>{t('timetable.remove_button')}</Button>
                                        </div>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </Card>
                )}
            </Page>
            {canManage && (
                <PeriodDialog
                    key={dialog === 'new' || dialog === null ? 'new' : `edit-${dialog.id}`}
                    open={dialog !== null}
                    period={dialog === 'new' ? null : dialog}
                    types={types}
                    onClose={() => setDialog(null)}
                />
            )}
            {confirmDialog}
        </AuthenticatedLayout>
    );
}
