import BookCopyDialog from '@/Components/BookCopyDialog';
import IssueLoanDialog from '@/Components/IssueLoanDialog';
import DateText from '@/Components/DateText';
import { BackLink, Button, Card, EmptyState, FlashMessages, Page, StatusBadge, useConfirm } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { BookOpenIcon, PlusIcon } from '@heroicons/react/24/outline';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

const STATUS_TONE = { available: 'success', issued: 'info', lost: 'danger', damaged: 'warning' };

export default function Show({ book, copies, branches, canManage, canPostSchoolWide }) {
    const { t } = useTranslation();
    const [addCopy, setAddCopy] = useState(false);
    const [issueFor, setIssueFor] = useState(null);
    const [ask, confirmDialog] = useConfirm();

    const removeCopy = async (copy) => {
        const ok = await ask({
            title: t('library.remove_copy_title', { number: copy.copy_number }),
            body: <p>{t('library.remove_copy_body')}</p>,
            confirmLabel: t('library.remove_copy_confirm'),
            tone: 'danger',
        });
        if (ok) router.delete(route('library-books.copies.destroy', [book.id, copy.id]), { preserveScroll: true });
    };

    const markLostOrDamaged = async (copy, status) => {
        const ok = await ask({
            title: t('library.mark_copy_title', { number: copy.copy_number, status: t(`library.status_${status}`) }),
            body: <p>{t('library.mark_copy_body')}</p>,
            confirmLabel: t('library.mark_copy_confirm', { status: t(`library.status_${status}`) }),
            tone: 'danger',
        });
        if (ok) router.post(route('library-books.copies.mark', [book.id, copy.id]), { status }, { preserveScroll: true });
    };

    const restore = (copy) => router.post(route('library-books.copies.restore', [book.id, copy.id]), {}, { preserveScroll: true });

    return (
        <AuthenticatedLayout header={<h2 className="truncate text-lg font-semibold text-gray-800 dark:text-gray-200">{book.title}</h2>}>
            <Page
                title={book.title}
                description={book.author}
                width="default"
                back={<BackLink href={route('library-books.index')}>{t('library.back_to_catalog')}</BackLink>}
                actions={canManage && <Button icon={PlusIcon} onClick={() => setAddCopy(true)}>{t('library.add_copy_button')}</Button>}
            >
                <FlashMessages errorKeys={['delete']} />

                {copies.length === 0 ? (
                    <Card><EmptyState icon={BookOpenIcon} title={t('library.no_copies_title')}>{t('library.no_copies_body')}</EmptyState></Card>
                ) : (
                    <div className="space-y-3">
                        {copies.map((c) => (
                            <Card key={c.id} data-testid="copy-row">
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <div>
                                        <p className="text-base font-semibold text-gray-900 dark:text-gray-100">{t('library.copy_number_x', { number: c.copy_number })}</p>
                                        <p className="text-sm text-gray-600 dark:text-gray-400">{c.branch_name}</p>
                                        {c.active_loan && (
                                            <p className="mt-1 text-sm text-gray-700 dark:text-gray-300">
                                                {t('library.on_loan_to', { name: c.active_loan.borrower_name })} · <DateText value={c.active_loan.due_date} />
                                            </p>
                                        )}
                                    </div>
                                    <div className="flex flex-wrap items-center gap-2">
                                        <StatusBadge tone={STATUS_TONE[c.status]} label={t(`library.status_${c.status}`)} />
                                        {canManage && c.status === 'available' && (
                                            <>
                                                <Button size="md" onClick={() => setIssueFor(c)}>{t('library.issue_button')}</Button>
                                                <Button size="md" variant="quiet" onClick={() => markLostOrDamaged(c, 'lost')}>{t('library.mark_lost_button')}</Button>
                                                <Button size="md" variant="quiet" onClick={() => markLostOrDamaged(c, 'damaged')}>{t('library.mark_damaged_button')}</Button>
                                                <Button size="md" variant="quiet" onClick={() => removeCopy(c)}>{t('library.remove_copy_confirm')}</Button>
                                            </>
                                        )}
                                        {canManage && ['lost', 'damaged'].includes(c.status) && (
                                            <Button size="md" variant="secondary" onClick={() => restore(c)}>{t('library.restore_button')}</Button>
                                        )}
                                    </div>
                                </div>
                            </Card>
                        ))}
                    </div>
                )}
            </Page>
            {canManage && (
                <>
                    <BookCopyDialog open={addCopy} bookId={book.id} branches={branches} canPostSchoolWide={canPostSchoolWide} onClose={() => setAddCopy(false)} />
                    <IssueLoanDialog key={`issue-${issueFor?.id ?? 'none'}`} open={issueFor !== null} copyId={issueFor?.id} bookTitle={book.title} onClose={() => setIssueFor(null)} />
                </>
            )}
            {confirmDialog}
        </AuthenticatedLayout>
    );
}
