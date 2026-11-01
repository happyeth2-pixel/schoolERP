import PerformanceCycleDialog from '@/Components/PerformanceCycleDialog';
import { BackLink, Button, Card, EmptyState, FlashMessages, Page, useConfirm } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { CalendarIcon, PlusIcon } from '@heroicons/react/24/outline';
import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

export default function Index({ cycles, canManage }) {
    const { t } = useTranslation();
    const { auth } = usePage().props;
    const [dialog, setDialog] = useState(null); // null | 'new' | a cycle object
    const [ask, confirmDialog] = useConfirm();

    const remove = async (cycle) => {
        if (await ask({
            title: t('performance.remove_cycle_title', { name: cycle.name }),
            body: t('performance.remove_cycle_body'),
            confirmLabel: t('performance.remove_cycle_confirm'),
            tone: 'danger',
        })) {
            router.delete(route('performance-cycles.destroy', cycle.id), { preserveScroll: true });
        }
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">{t('performance.cycles_title')}</h2>}>
            <Page
                title={t('performance.cycles_title')}
                description={t('performance.cycles_desc')}
                back={<BackLink big={auth.role === 'teacher'} href={route('dashboard')}>{t('common.back')}</BackLink>}
                actions={canManage && <Button icon={PlusIcon} onClick={() => setDialog('new')}>{t('performance.add_cycle_button')}</Button>}
            >
                <FlashMessages errorKeys={['name']} />

                {cycles.length === 0 ? (
                    <Card><EmptyState icon={CalendarIcon} title={t('performance.empty_cycles_title')}>{t('performance.empty_cycles_body')}</EmptyState></Card>
                ) : (
                    <Card>
                        <ul className="divide-y divide-stone-200 dark:divide-stone-700" data-testid="performance-cycle-list">
                            {cycles.map((c) => (
                                <li key={c.id} className="flex flex-wrap items-center justify-between gap-2 py-3" data-testid="performance-cycle-row">
                                    <div>
                                        <span className="text-base font-semibold text-stone-900 dark:text-stone-100">{c.name}</span>
                                        <p className="text-sm text-stone-600 dark:text-stone-400">
                                            {c.start_date} &ndash; {c.end_date} &middot; {t('performance.reviews_count_x', { count: c.reviews_count })}
                                        </p>
                                    </div>
                                    {canManage && (
                                        <div className="flex items-center gap-3">
                                            <Button size="sm" variant="quiet" onClick={() => setDialog(c)}>{t('performance.edit_button')}</Button>
                                            <Button size="sm" variant="quiet" onClick={() => remove(c)}>{t('performance.remove_button')}</Button>
                                        </div>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </Card>
                )}
            </Page>
            <PerformanceCycleDialog
                key={dialog === 'new' || dialog === null ? 'new' : `edit-${dialog.id}`}
                open={dialog !== null}
                cycle={dialog === 'new' ? null : dialog}
                onClose={() => setDialog(null)}
            />
            {confirmDialog}
        </AuthenticatedLayout>
    );
}
