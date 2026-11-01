import ContractDialog from '@/Components/ContractDialog';
import LeaveAllocationDialog from '@/Components/LeaveAllocationDialog';
import LeaveRequestDialog from '@/Components/LeaveRequestDialog';
import PerformanceReviewDialog from '@/Components/PerformanceReviewDialog';
import { BackLink, Button, Card, FlashMessages, Page, SectionHeader, StatusBadge, useConfirm } from '@/Components/ui';
import { useCalendar } from '@/useCalendar';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

const STATUS_TONE = { active: 'success', on_leave: 'info', suspended: 'warning', terminated: 'danger' };
const CONTRACT_STATUS_TONE = { draft: 'neutral', active: 'success', expiring: 'warning', expired: 'danger', terminated: 'danger' };
const LEAVE_STATUS_TONE = { draft: 'neutral', submitted: 'info', approved: 'success', rejected: 'danger' };
const PERFORMANCE_STATUS_TONE = { draft: 'neutral', finalized: 'success' };
const money = (n) => Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

function Row({ label, value }) {
    if (!value) return null;

    return (
        <div className="flex flex-col gap-1 py-2 sm:flex-row sm:items-center sm:justify-between">
            <dt className="text-sm text-stone-600 dark:text-stone-400">{label}</dt>
            <dd className="text-base font-medium text-stone-900 dark:text-stone-100">{value}</dd>
        </div>
    );
}

export default function Show({
    employee, canManage, canViewContracts, canManageContracts, contracts, canViewLeave, canManageLeave, leaveTypes, leaveAllocations, leaveRequests,
    canViewPerformance, canManagePerformance, performanceCycles, performanceReviews, canViewPayroll, payslips,
}) {
    const { t } = useTranslation();
    const { format } = useCalendar();
    const [contractDialog, setContractDialog] = useState(null); // null | 'new' | a contract object
    const [allocationDialog, setAllocationDialog] = useState(null); // null | 'new' | an allocation object
    const [leaveDialog, setLeaveDialog] = useState(null); // null | 'new' | a leave request object
    const [reviewDialog, setReviewDialog] = useState(null); // null | 'new' | a performance review object
    const [ask, confirmDialog] = useConfirm();

    const removeContract = async (contract) => {
        if (await ask({
            title: t('contract.remove_title'),
            body: t('contract.remove_body'),
            confirmLabel: t('contract.remove_confirm'),
            tone: 'danger',
        })) {
            router.delete(route('employment-contracts.destroy', contract.id), { preserveScroll: true });
        }
    };

    const removeLeaveRequest = async (r) => {
        if (await ask({
            title: t('leave.remove_request_title'),
            body: t('leave.remove_request_body'),
            confirmLabel: t('leave.remove_request_confirm'),
            tone: 'danger',
        })) {
            router.delete(route('leave-requests.destroy', r.id), { preserveScroll: true });
        }
    };

    const removePerformanceReview = async (r) => {
        if (await ask({
            title: t('performance.remove_review_title'),
            body: t('performance.remove_review_body'),
            confirmLabel: t('performance.remove_review_confirm'),
            tone: 'danger',
        })) {
            router.delete(route('performance-reviews.destroy', r.id), { preserveScroll: true });
        }
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">{employee.full_name}</h2>}>
            <Page
                title={employee.full_name}
                back={<BackLink href={route('employees.index')}>{t('employee.back_to_list')}</BackLink>}
                actions={canManage && <Button as={Link} href={route('employees.edit', employee.id)}>{t('employee.edit_button')}</Button>}
            >
                <FlashMessages errorKeys={['contract', 'leave', 'performance']} />

                <Card>
                    <div className="flex flex-wrap items-center gap-4">
                        {employee.photo_url ? (
                            <img src={employee.photo_url} alt="" className="h-20 w-20 rounded-full object-cover" />
                        ) : (
                            <div className="flex h-20 w-20 items-center justify-center rounded-full bg-stone-100 text-2xl font-semibold text-stone-500 dark:bg-stone-700 dark:text-stone-300">
                                {employee.full_name.charAt(0).toUpperCase()}
                            </div>
                        )}
                        <div>
                            <p className="text-xl font-bold text-stone-900 dark:text-stone-100">{employee.full_name}</p>
                            <p className="text-sm text-stone-600 dark:text-stone-400">{employee.employee_number}</p>
                            <StatusBadge tone={STATUS_TONE[employee.employment_status]} label={t(`employee.status_${employee.employment_status}`)} />
                        </div>
                    </div>
                </Card>

                <Card>
                    <SectionHeader as="h3" title={t('employee.section_employment')} />
                    <dl className="divide-y divide-stone-200 dark:divide-stone-700">
                        <Row label={t('employee.job_title')} value={employee.position} />
                        <Row label={t('employee.department')} value={employee.department} />
                        <Row label={t('employee.branch')} value={employee.branch} />
                        <Row label={t('employee.hire_date')} value={employee.hire_date ? format(employee.hire_date) : null} />
                        <Row label={t('employee.employment_type')} value={t(`employee.type_${employee.employment_type}`)} />
                        <Row label={t('employee.qualifications')} value={employee.qualifications} />
                    </dl>
                </Card>

                <Card>
                    <SectionHeader as="h3" title={t('employee.section_contact')} />
                    <dl className="divide-y divide-stone-200 dark:divide-stone-700">
                        <Row label={t('employee.phone')} value={employee.phone} />
                        <Row label={t('employee.email')} value={employee.email} />
                        <Row label={t('employee.address')} value={employee.address} />
                        <Row label={t('employee.gender')} value={employee.gender ? t(`employee.gender_${employee.gender}`) : null} />
                        <Row label={t('employee.age')} value={employee.age !== null && employee.age !== undefined ? t('enroll.age_years', { count: employee.age }) : null} />
                        <Row label={t('employee.emergency_contact_name')} value={employee.emergency_contact_name} />
                        <Row label={t('employee.emergency_contact_phone')} value={employee.emergency_contact_phone} />
                    </dl>
                </Card>

                <Card>
                    <SectionHeader as="h3" title={t('employee.section_account')} />
                    {employee.linked_user ? (
                        <p className="text-base text-stone-800 dark:text-stone-200" data-testid="linked-account">
                            {t('employee.linked_to_x', { name: employee.linked_user.name, phone: employee.linked_user.phone ?? '' })}
                        </p>
                    ) : (
                        <p className="text-base text-stone-600 dark:text-stone-400" data-testid="no-linked-account">{t('employee.not_linked')}</p>
                    )}
                </Card>

                {employee.notes && (
                    <Card>
                        <SectionHeader as="h3" title={t('employee.notes')} />
                        <p className="whitespace-pre-wrap text-base text-stone-800 dark:text-stone-200">{employee.notes}</p>
                    </Card>
                )}

                {canViewContracts && (
                    <Card>
                        <SectionHeader
                            as="h3"
                            title={t('contract.section_title')}
                            action={canManageContracts && <Button size="sm" onClick={() => setContractDialog('new')}>{t('contract.add_button')}</Button>}
                        />
                        {contracts.length === 0 ? (
                            <p className="text-base text-stone-600 dark:text-stone-400" data-testid="no-contracts">{t('contract.empty_body')}</p>
                        ) : (
                            <ul className="divide-y divide-stone-200 dark:divide-stone-700" data-testid="contract-list">
                                {contracts.map((c) => (
                                    <li key={c.id} className="py-3" data-testid="contract-row">
                                        <div className="flex flex-wrap items-center justify-between gap-2">
                                            <div>
                                                <span className="text-base font-semibold text-stone-900 dark:text-stone-100">{t(`contract.type_${c.contract_type}`)}</span>
                                                <StatusBadge tone={CONTRACT_STATUS_TONE[c.status]} label={t(`contract.status_${c.status}`)} className="ml-2" />
                                            </div>
                                            {canManageContracts && (
                                                <div className="flex items-center gap-3">
                                                    <Button size="sm" variant="quiet" onClick={() => setContractDialog(c)}>{t('contract.edit_button')}</Button>
                                                    {c.can_remove && <Button size="sm" variant="quiet" onClick={() => removeContract(c)}>{t('contract.remove_button')}</Button>}
                                                </div>
                                            )}
                                        </div>
                                        <p className="text-sm text-stone-600 dark:text-stone-400">
                                            {t('contract.dated_x', { start: c.start_date, end: c.end_date ?? t('contract.no_end_date') })}
                                            {c.salary_reference != null && ` · ${money(c.salary_reference)} ETB`}
                                        </p>
                                        {c.has_document && (
                                            <a href={route('employment-contracts.document', c.id)} target="_blank" rel="noreferrer" className="text-sm font-medium text-brand-600 hover:underline dark:text-brand-400">
                                                {t('contract.view_document')}
                                            </a>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>
                )}

                {canViewLeave && (
                    <Card>
                        <SectionHeader
                            as="h3"
                            title={t('leave.section_title')}
                            action={canManageLeave && (
                                <div className="flex gap-2">
                                    <Button size="sm" variant="quiet" onClick={() => setAllocationDialog('new')}>{t('leave.add_allocation_button')}</Button>
                                    {leaveTypes.length > 0 && <Button size="sm" onClick={() => setLeaveDialog('new')}>{t('leave.request_on_behalf_button')}</Button>}
                                </div>
                            )}
                        />
                        {leaveAllocations.length > 0 && (
                            <ul className="mb-4 divide-y divide-stone-200 dark:divide-stone-700" data-testid="leave-balance-list">
                                {leaveAllocations.map((a) => (
                                    <li key={a.id} className="py-2" data-testid="leave-balance-row">
                                        <p className="text-sm font-semibold text-stone-900 dark:text-stone-100">{a.leave_type}</p>
                                        <p className="text-sm text-stone-600 dark:text-stone-400">{t('leave.balance_x', { balance: a.balance, used: a.used_days, allocated: a.allocated_days })}</p>
                                    </li>
                                ))}
                            </ul>
                        )}
                        {leaveRequests.length === 0 ? (
                            <p className="text-base text-stone-600 dark:text-stone-400" data-testid="no-leave-requests">{t('leave.no_requests')}</p>
                        ) : (
                            <ul className="divide-y divide-stone-200 dark:divide-stone-700" data-testid="leave-request-list">
                                {leaveRequests.map((r) => (
                                    <li key={r.id} className="py-3" data-testid="leave-request-row">
                                        <div className="flex flex-wrap items-center justify-between gap-2">
                                            <div>
                                                <span className="text-base font-semibold text-stone-900 dark:text-stone-100">{r.leave_type}</span>
                                                <StatusBadge tone={LEAVE_STATUS_TONE[r.status]} label={t(`leave.status_${r.status}`)} className="ml-2" />
                                            </div>
                                            {canManageLeave && r.can_remove && (
                                                <Button size="sm" variant="quiet" onClick={() => removeLeaveRequest(r)}>{t('leave.remove_button')}</Button>
                                            )}
                                        </div>
                                        <p className="text-sm text-stone-600 dark:text-stone-400">{t('leave.dated_x', { start: r.start_date, end: r.end_date, count: r.days_count })}</p>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>
                )}

                {canViewPerformance && (
                    <Card>
                        <SectionHeader
                            as="h3"
                            title={t('performance.section_title')}
                            action={canManagePerformance && performanceCycles.length > 0 && <Button size="sm" onClick={() => setReviewDialog('new')}>{t('performance.add_review_button')}</Button>}
                        />
                        {performanceReviews.length === 0 ? (
                            <p className="text-base text-stone-600 dark:text-stone-400" data-testid="no-performance-reviews">{t('performance.no_reviews')}</p>
                        ) : (
                            <ul className="divide-y divide-stone-200 dark:divide-stone-700" data-testid="performance-review-list">
                                {performanceReviews.map((r) => (
                                    <li key={r.id} className="py-3" data-testid="performance-review-row">
                                        <div className="flex flex-wrap items-center justify-between gap-2">
                                            <div>
                                                <span className="text-base font-semibold text-stone-900 dark:text-stone-100">{r.cycle}</span>
                                                <StatusBadge tone={PERFORMANCE_STATUS_TONE[r.status]} label={t(`performance.status_${r.status}`)} className="ml-2" />
                                            </div>
                                            {canManagePerformance && (
                                                <div className="flex items-center gap-3">
                                                    {r.can_remove && <Button size="sm" variant="quiet" onClick={() => setReviewDialog(r)}>{t('performance.edit_button')}</Button>}
                                                    {r.can_remove && <Button size="sm" variant="quiet" onClick={() => removePerformanceReview(r)}>{t('performance.remove_button')}</Button>}
                                                </div>
                                            )}
                                        </div>
                                        <p className="text-sm text-stone-600 dark:text-stone-400">{t(`performance.rating_${r.rating}`)}</p>
                                        <p className="mt-2 whitespace-pre-wrap text-base text-stone-800 dark:text-stone-200">{r.comments}</p>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>
                )}

                {canViewPayroll && (
                    <Card>
                        <SectionHeader as="h3" title={t('payroll.section_title')} />
                        {payslips.length === 0 ? (
                            <p className="text-base text-stone-600 dark:text-stone-400" data-testid="no-payslips">{t('payroll.no_payslips')}</p>
                        ) : (
                            <ul className="divide-y divide-stone-200 dark:divide-stone-700" data-testid="payslip-list">
                                {payslips.map((p) => (
                                    <li key={p.id} className="py-3" data-testid="payslip-row">
                                        <p className="text-base font-semibold text-stone-900 dark:text-stone-100">{p.period}</p>
                                        <p className="text-sm text-stone-600 dark:text-stone-400">
                                            {t('payroll.col_net_pay')}: {money(p.net_pay)}
                                        </p>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>
                )}
            </Page>

            <ContractDialog
                key={contractDialog === 'new' || contractDialog === null ? 'new' : `edit-${contractDialog.id}`}
                open={contractDialog !== null}
                employeeId={employee.id}
                contract={contractDialog === 'new' ? null : contractDialog}
                onClose={() => setContractDialog(null)}
            />
            {canManageLeave && (
                <LeaveAllocationDialog
                    key={allocationDialog === 'new' || allocationDialog === null ? 'new' : `edit-${allocationDialog.id}`}
                    open={allocationDialog !== null}
                    employeeId={employee.id}
                    leaveTypes={leaveTypes}
                    allocation={allocationDialog === 'new' ? null : allocationDialog}
                    onClose={() => setAllocationDialog(null)}
                />
            )}
            {canManageLeave && leaveTypes.length > 0 && (
                <LeaveRequestDialog
                    key={leaveDialog === 'new' || leaveDialog === null ? 'new' : `edit-${leaveDialog.id}`}
                    open={leaveDialog !== null}
                    employeeId={employee.id}
                    leaveTypes={leaveTypes}
                    request={leaveDialog === 'new' ? null : leaveDialog}
                    onClose={() => setLeaveDialog(null)}
                />
            )}
            {canManagePerformance && performanceCycles.length > 0 && (
                <PerformanceReviewDialog
                    key={reviewDialog === 'new' || reviewDialog === null ? 'new' : `edit-${reviewDialog.id}`}
                    open={reviewDialog !== null}
                    employeeId={employee.id}
                    cycles={performanceCycles}
                    review={reviewDialog === 'new' ? null : reviewDialog}
                    onClose={() => setReviewDialog(null)}
                />
            )}
            {confirmDialog}
        </AuthenticatedLayout>
    );
}
