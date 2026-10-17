import PeriodDialog from '@/Components/PeriodDialog';
import { Button, Card, EmptyState, FlashMessages, Page, useConfirm } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { ClockIcon, PlusIcon } from '@heroicons/react/24/outline';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

export default function Index({ periods, canManage }) {
    const { t } = useTranslation();
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
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">{t('timetable.periods_title')}</h2>}>
            <Page
                title={t('timetable.periods_title')}
                description={t('timetable.periods_desc')}
                actions={canManage && <Button icon={PlusIcon} onClick={() => setDialog('new')}>{t('timetable.add_period_button')}</Button>}
            >
                <FlashMessages errorKeys={['name']} />

                {periods.length === 0 ? (
                    <Card><EmptyState icon={ClockIcon} title={t('timetable.empty_periods_title')}>{t('timetable.empty_periods_body')}</EmptyState></Card>
                ) : (
                    <Card>
                        <ul className="divide-y divide-gray-200 dark:divide-gray-700" data-testid="period-list">
                            {periods.map((p) => (
                                <li key={p.id} className="flex flex-wrap items-center justify-between gap-2 py-3" data-testid="period-row">
                                    <div>
                                        <span className="text-base font-semibold text-gray-900 dark:text-gray-100">{p.name || `Period ${p.period_number}`}</span>
                                        <p className="text-sm text-gray-600 dark:text-gray-400">{p.start_time} &ndash; {p.end_time}</p>
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
                    onClose={() => setDialog(null)}
                />
            )}
            {confirmDialog}
        </AuthenticatedLayout>
    );
}
