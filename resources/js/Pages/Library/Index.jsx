import MarkLoanLostDamagedDialog from '@/Components/MarkLoanLostDamagedDialog';
import DateText from '@/Components/DateText';
import { Alert, BackLink, Button, Card, EmptyState, FlashMessages, Page, StatusBadge, useConfirm } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { BookOpenIcon } from '@heroicons/react/24/outline';
import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

export default function Index({ loans, unresolvedFines, today, canManage, fineConfigured }) {
    const { t } = useTranslation();
    const { auth } = usePage().props;
    const [markFor, setMarkFor] = useState(null);
    const [ask, confirmDialog] = useConfirm();

    const returnLoan = async (loan) => {
        const ok = await ask({
            title: t('library.return_title', { book: loan.book_title }),
            body: loan.estimated_fine ? <p>{t('library.return_body_fine', { amount: loan.estimated_fine })}</p> : <p>{t('library.return_body_ok')}</p>,
            confirmLabel: t('library.return_confirm'),
        });
        if (ok) router.post(route('library.loans.return', loan.id), {}, { preserveScroll: true });
    };

    const renew = (loan) => router.post(route('library.loans.renew', loan.id), {}, { preserveScroll: true });

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">{t('library.loans_title')}</h2>}>
            <Page title={t('library.loans_title')} description={t('library.loans_desc')} width="default" back={<BackLink big={auth.role === 'teacher'} href={route('dashboard')}>{t('common.back')}</BackLink>}>
                <FlashMessages />

                {!fineConfigured && canManage && <Alert tone="info">{t('library.fines_off_note')}</Alert>}

                {loans.length === 0 ? (
                    <Card><EmptyState icon={BookOpenIcon} title={t('library.no_active_loans_title')}>{t('library.no_active_loans_body')}</EmptyState></Card>
                ) : (
                    <div className="space-y-3">
                        {loans.map((l) => (
                            <Card key={l.id} data-testid="loan-row" data-overdue={l.overdue}>
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <div>
                                        <p className="text-base font-semibold text-stone-900 dark:text-stone-100">{l.book_title} <span className="font-normal text-stone-500">#{l.copy_number}</span></p>
                                        <p className="text-sm text-stone-600 dark:text-stone-400">{t('library.borrower_line', { name: l.borrower_name, branch: l.branch_name })}</p>
                                        <p className="text-sm text-stone-700 dark:text-stone-300">
                                            {t('library.due_on')} <DateText value={l.due_date} />
                                            {l.renewed_count > 0 && ` · ${t('library.renewed_n_times', { count: l.renewed_count })}`}
                                            {l.estimated_fine && ` · ${t('library.estimated_fine', { amount: l.estimated_fine })}`}
                                        </p>
                                    </div>
                                    <div className="flex flex-wrap items-center gap-2">
                                        {l.overdue && <StatusBadge tone="danger" label={t('library.overdue_badge')} />}
                                        {canManage && (
                                            <>
                                                <Button size="md" onClick={() => returnLoan(l)}>{t('library.return_button')}</Button>
                                                <Button size="md" variant="secondary" onClick={() => renew(l)}>{t('library.renew_button')}</Button>
                                                <Button size="md" variant="quiet" onClick={() => setMarkFor(l)}>{t('library.lost_damaged_button')}</Button>
                                            </>
                                        )}
                                    </div>
                                </div>
                            </Card>
                        ))}
                    </div>
                )}

                {canManage && unresolvedFines.length > 0 && (
                    <>
                        <h3 className="text-lg font-bold text-stone-900 dark:text-stone-100">{t('library.unresolved_fines_title')}</h3>
                        <div className="space-y-3">
                            {unresolvedFines.map((f) => (
                                <Card key={f.id} data-testid="fine-row">
                                    <div className="flex flex-wrap items-center justify-between gap-3">
                                        <div>
                                            <p className="text-base font-semibold text-stone-900 dark:text-stone-100">{f.book_title}</p>
                                            <p className="text-sm text-stone-600 dark:text-stone-400">{t('library.borrower_owes', { name: f.borrower_name, amount: f.fine_amount })}</p>
                                        </div>
                                        <div className="flex gap-2">
                                            <Button size="md" onClick={() => router.post(route('library.loans.pay-fine', f.id), {}, { preserveScroll: true })}>{t('library.mark_paid_button')}</Button>
                                            <Button size="md" variant="quiet" onClick={() => router.post(route('library.loans.waive-fine', f.id), {}, { preserveScroll: true })}>{t('library.waive_button')}</Button>
                                        </div>
                                    </div>
                                </Card>
                            ))}
                        </div>
                    </>
                )}
            </Page>
            {canManage && <MarkLoanLostDamagedDialog open={markFor !== null} loan={markFor} onClose={() => setMarkFor(null)} />}
            {confirmDialog}
        </AuthenticatedLayout>
    );
}
