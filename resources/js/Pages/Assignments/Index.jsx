import AssignmentDialog from '@/Components/AssignmentDialog';
import DateText from '@/Components/DateText';
import { Button, Card, EmptyState, FlashMessages, Page, StatusBadge, useConfirm } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { BookOpenIcon, PaperClipIcon, PlusIcon, TrashIcon } from '@heroicons/react/24/outline';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

export default function Index({ assignments, teachingAssignments }) {
    const { t } = useTranslation();
    const [dialog, setDialog] = useState(null); // null | 'new' | an assignment object
    const [ask, confirmDialog] = useConfirm();
    const title = t('homework.manage_title');

    const remove = async (assignment) => {
        const ok = await ask({
            title: t('homework.remove_confirm_title'),
            body: <p>{t('homework.remove_confirm_body', { title: assignment.title })}</p>,
            confirmLabel: t('homework.remove_button'),
            tone: 'danger',
        });
        if (ok) router.delete(route('assignments.destroy', assignment.id), { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout header={<h2 className="truncate text-lg font-semibold text-gray-800 dark:text-gray-200">{title}</h2>}>
            <Page
                title={title}
                description={t('homework.manage_desc')}
                width="default"
                actions={teachingAssignments.length > 0 && <Button icon={PlusIcon} onClick={() => setDialog('new')}>{t('homework.add_button')}</Button>}
            >
                <FlashMessages errorKeys={['delete']} />

                {teachingAssignments.length === 0 ? (
                    <Card><EmptyState icon={BookOpenIcon} title={t('homework.no_classes_title')}>{t('homework.no_classes_body')}</EmptyState></Card>
                ) : assignments.length === 0 ? (
                    <Card><EmptyState icon={BookOpenIcon} title={t('homework.none_title')}>{t('homework.none_body')}</EmptyState></Card>
                ) : (
                    <div className="space-y-3">
                        {assignments.map((a) => (
                            <Card key={a.id} data-testid="assignment-row">
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <p className="text-sm font-semibold uppercase tracking-wide text-indigo-600 dark:text-indigo-400">{a.section_label} · {a.subject_name}</p>
                                        <p className="text-lg font-bold text-gray-900 dark:text-gray-100">{a.title}</p>
                                        <p className="text-sm text-gray-600 dark:text-gray-400">{t('homework.due_label')} <DateText value={a.due_date} /> · {a.teacher_name}</p>
                                        {a.description && <p className="mt-2 whitespace-pre-line text-base text-gray-800 dark:text-gray-200">{a.description}</p>}
                                        {a.has_attachment && (
                                            <a href={route('assignments.document', a.id)} target="_blank" rel="noreferrer" className="mt-2 inline-flex items-center gap-1 text-sm font-medium text-indigo-600 hover:underline dark:text-indigo-400">
                                                <PaperClipIcon className="h-4 w-4" /> {t('homework.view_attachment')}
                                            </a>
                                        )}
                                    </div>
                                    <div className="flex shrink-0 flex-wrap items-center gap-2">
                                        <StatusBadge status={a.status} />
                                        <Button size="md" variant="quiet" onClick={() => setDialog(a)}>{t('homework.edit_button')}</Button>
                                        <Button size="md" variant="quiet" icon={TrashIcon} onClick={() => remove(a)} aria-label={t('homework.remove_button')} />
                                    </div>
                                </div>
                            </Card>
                        ))}
                    </div>
                )}
            </Page>

            <AssignmentDialog
                key={dialog === 'new' || dialog === null ? 'new' : `edit-${dialog.id}`}
                open={dialog !== null}
                teachingAssignments={teachingAssignments}
                assignment={dialog === 'new' ? null : dialog}
                onClose={() => setDialog(null)}
            />
            {confirmDialog}
        </AuthenticatedLayout>
    );
}
