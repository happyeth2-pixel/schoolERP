import LeaveRequestDialog from '@/Components/LeaveRequestDialog';
import { BackLink, Button, Card, EmptyState, FlashMessages, Page, SectionHeader, StatusBadge, useConfirm } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { CalendarIcon } from '@heroicons/react/24/outline';
import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

const STATUS_TONE = { draft: 'neutral', submitted: 'info', approved: 'success', rejected: 'danger' };

export default function Mine({ employee, leaveTypes, allocations, requests }) {
    const { t } = useTranslation();
    const { auth } = usePage().props;
    const [dialog, setDialog] = useState(null); // null | 'new' | a request object
    const [ask, confirmDialog] = useConfirm();

    const remove = async (r) => {
        if (await ask({
            title: t('leave.remove_request_title'),
            body: t('leave.remove_request_body'),
            confirmLabel: t('leave.remove_request_confirm'),
            tone: 'danger',
        })) {
            router.delete(route('leave-requests.destroy', r.id), { preserveScroll: true });
        }
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">{t('leave.my_leave_title')}</h2>}>
            <Page
                title={t('leave.my_leave_title')}
                description={t('leave.my_leave_desc')}
                back={<BackLink big={auth.role === 'teacher'} href={route('dashboard')}>{t('common.back')}</BackLink>}
            >
                <FlashMessages errorKeys={['leave']} />

                {!employee ? (
                    <Card><EmptyState icon={CalendarIcon} title={t('leave.no_employee_record_title')}>{t('leave.no_employee_record_body')}</EmptyState></Card>
                ) : (
                    <>
                        <Card>
                            <SectionHeader as="h3" title={t('leave.balances_title')} />
                            {allocations.length === 0 ? (
                                <p className="text-base text-stone-600 dark:text-stone-400">{t('leave.no_allocations')}</p>
                            ) : (
                                <ul className="divide-y divide-stone-200 dark:divide-stone-700" data-testid="leave-balance-list">
                                    {allocations.map((a) => (
                                        <li key={a.id} className="py-3" data-testid="leave-balance-row">
                                            <p className="text-base font-semibold text-stone-900 dark:text-stone-100">{a.leave_type}</p>
                                            <p className="text-sm text-stone-600 dark:text-stone-400">{t('leave.balance_x', { balance: a.balance, used: a.used_days, allocated: a.allocated_days })}</p>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Card>

                        <Card>
                            <SectionHeader
                                as="h3"
                                title={t('leave.my_requests_title')}
                                action={leaveTypes.length > 0 && <Button size="sm" onClick={() => setDialog('new')}>{t('leave.request_button')}</Button>}
                            />
                            {requests.length === 0 ? (
                                <p className="text-base text-stone-600 dark:text-stone-400" data-testid="no-leave-requests">{t('leave.no_requests')}</p>
                            ) : (
                                <ul className="divide-y divide-stone-200 dark:divide-stone-700" data-testid="leave-request-list">
                                    {requests.map((r) => (
                                        <li key={r.id} className="py-3" data-testid="leave-request-row">
                                            <div className="flex flex-wrap items-center justify-between gap-2">
                                                <div>
                                                    <span className="text-base font-semibold text-stone-900 dark:text-stone-100">{r.leave_type}</span>
                                                    <StatusBadge tone={STATUS_TONE[r.status]} label={t(`leave.status_${r.status}`)} className="ml-2" />
                                                </div>
                                                {r.can_edit && (
                                                    <div className="flex items-center gap-3">
                                                        <Button size="sm" variant="quiet" onClick={() => setDialog(r)}>{t('leave.edit_button')}</Button>
                                                        <Button size="sm" variant="quiet" onClick={() => remove(r)}>{t('leave.remove_button')}</Button>
                                                    </div>
                                                )}
                                            </div>
                                            <p className="text-sm text-stone-600 dark:text-stone-400">{t('leave.dated_x', { start: r.start_date, end: r.end_date, count: r.days_count })}</p>
                                            {r.status === 'rejected' && r.rejection_reason && (
                                                <p className="text-sm text-red-700 dark:text-red-400">{t('leave.rejection_reason_x', { reason: r.rejection_reason })}</p>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Card>
                    </>
                )}
            </Page>

            {employee && (
                <LeaveRequestDialog
                    key={dialog === 'new' || dialog === null ? 'new' : `edit-${dialog.id}`}
                    open={dialog !== null}
                    employeeId={employee.id}
                    leaveTypes={leaveTypes}
                    request={dialog === 'new' ? null : dialog}
                    onClose={() => setDialog(null)}
                />
            )}
            {confirmDialog}
        </AuthenticatedLayout>
    );
}
