import LeaveTypeDialog from '@/Components/LeaveTypeDialog';
import { BackLink, Button, Card, EmptyState, FlashMessages, Page, useConfirm } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { CalendarIcon, PlusIcon } from '@heroicons/react/24/outline';
import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

export default function Index({ leaveTypes, canManage }) {
    const { t } = useTranslation();
    const { auth } = usePage().props;
    const [dialog, setDialog] = useState(null); // null | 'new' | a leaveType object
    const [ask, confirmDialog] = useConfirm();

    const remove = async (lt) => {
        if (await ask({
            title: t('leave.remove_type_title', { name: lt.name }),
            body: t('leave.remove_type_body'),
            confirmLabel: t('leave.remove_type_confirm'),
            tone: 'danger',
        })) {
            router.delete(route('leave-types.destroy', lt.id), { preserveScroll: true });
        }
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">{t('leave.types_title')}</h2>}>
            <Page
                title={t('leave.types_title')}
                description={t('leave.types_desc')}
                back={<BackLink big={auth.role === 'teacher'} href={route('dashboard')}>{t('common.back')}</BackLink>}
                actions={canManage && <Button icon={PlusIcon} onClick={() => setDialog('new')}>{t('leave.add_type_button')}</Button>}
            >
                <FlashMessages errorKeys={['name']} />

                {leaveTypes.length === 0 ? (
                    <Card><EmptyState icon={CalendarIcon} title={t('leave.empty_types_title')}>{t('leave.empty_types_body')}</EmptyState></Card>
                ) : (
                    <Card>
                        <ul className="divide-y divide-stone-200 dark:divide-stone-700" data-testid="leave-type-list">
                            {leaveTypes.map((lt) => (
                                <li key={lt.id} className="flex flex-wrap items-center justify-between gap-2 py-3" data-testid="leave-type-row">
                                    <div>
                                        <span className="text-base font-semibold text-stone-900 dark:text-stone-100">{lt.name}</span>
                                        {lt.default_annual_days != null && <span className="ml-2 text-sm text-stone-600 dark:text-stone-400">{t('leave.default_days_x', { count: lt.default_annual_days })}</span>}
                                        {lt.requires_document && <span className="ml-2 text-sm text-amber-700 dark:text-amber-400">{t('leave.requires_document')}</span>}
                                        <p className="text-sm text-stone-600 dark:text-stone-400">{t('leave.allocations_and_requests_x', { allocations: lt.allocations_count, requests: lt.requests_count })}</p>
                                    </div>
                                    {canManage && (
                                        <div className="flex items-center gap-3">
                                            <Button size="sm" variant="quiet" onClick={() => setDialog(lt)}>{t('leave.edit_button')}</Button>
                                            <Button size="sm" variant="quiet" onClick={() => remove(lt)}>{t('leave.remove_button')}</Button>
                                        </div>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </Card>
                )}
            </Page>
            <LeaveTypeDialog
                key={dialog === 'new' || dialog === null ? 'new' : `edit-${dialog.id}`}
                open={dialog !== null}
                leaveType={dialog === 'new' ? null : dialog}
                onClose={() => setDialog(null)}
            />
            {confirmDialog}
        </AuthenticatedLayout>
    );
}
