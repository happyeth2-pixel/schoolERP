import DisciplineIncidentDialog from '@/Components/DisciplineIncidentDialog';
import DateText from '@/Components/DateText';
import { Alert, BackLink, Button, Card, EmptyState, FlashMessages, Page, StatusBadge } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { ExclamationTriangleIcon, PaperClipIcon, PlusIcon } from '@heroicons/react/24/outline';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

const STATUS_TONE = { open: 'warning', under_review: 'info', resolved: 'success' };

export default function ForStudent({ student, incidents, categories, actions, statuses, canManage }) {
    const { t } = useTranslation();
    const [dialog, setDialog] = useState(null); // null | 'new' | an incident object

    return (
        <AuthenticatedLayout header={<h2 className="truncate text-lg font-semibold text-stone-800 dark:text-stone-200">{t('discipline.page_title')}</h2>}>
            <Page
                title={t('discipline.title_for', { name: student.name })}
                description={t('discipline.page_desc')}
                width="default"
                back={<BackLink href={route('students.edit', student.id)}>{t('discipline.back_to_profile')}</BackLink>}
                actions={canManage && <Button icon={PlusIcon} onClick={() => setDialog('new')}>{t('discipline.add_button')}</Button>}
            >
                <FlashMessages />
                <Alert tone="info">{t('discipline.confidential_note')}</Alert>

                {incidents.length === 0 ? (
                    <Card><EmptyState icon={ExclamationTriangleIcon} title={t('discipline.none_title')}>{t('discipline.none_body')}</EmptyState></Card>
                ) : (
                    <div className="space-y-3">
                        {incidents.map((i) => (
                            <Card key={i.id} data-testid="incident-row">
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <p className="text-base font-semibold text-stone-900 dark:text-stone-100">{t(`discipline.category_${i.category}`)}</p>
                                        <p className="text-sm text-stone-600 dark:text-stone-400">
                                            <DateText value={i.incident_date} /> · {t('discipline.reported_by_x', { name: i.reported_by })}
                                        </p>
                                        <p className="mt-2 whitespace-pre-line text-base text-stone-800 dark:text-stone-200">{i.description}</p>
                                        <p className="mt-2 text-sm text-stone-700 dark:text-stone-300">{t('discipline.action_taken_x', { action: t(`discipline.action_${i.action_taken}`) })}</p>
                                        {i.has_attachment && (
                                            <a href={route('discipline.document', i.id)} target="_blank" rel="noreferrer" className="mt-2 inline-flex items-center gap-1 text-sm font-medium text-brand-600 hover:underline dark:text-brand-400">
                                                <PaperClipIcon className="h-4 w-4" /> {t('discipline.view_attachment')}
                                            </a>
                                        )}
                                        {i.resolved_by && <p className="mt-2 text-sm text-stone-600 dark:text-stone-400">{t('discipline.resolved_by_x', { name: i.resolved_by, when: i.resolved_at })}</p>}
                                    </div>
                                    <div className="flex shrink-0 flex-wrap items-center gap-2">
                                        <StatusBadge tone={STATUS_TONE[i.status]} label={t(`discipline.status_${i.status}`)} />
                                        {canManage && <Button size="md" variant="quiet" onClick={() => setDialog(i)}>{t('discipline.edit_button')}</Button>}
                                    </div>
                                </div>
                            </Card>
                        ))}
                    </div>
                )}
            </Page>
            {canManage && (
                <DisciplineIncidentDialog
                    key={dialog === 'new' || dialog === null ? 'new' : `edit-${dialog.id}`}
                    open={dialog !== null}
                    studentId={student.id}
                    incident={dialog === 'new' ? null : dialog}
                    categories={categories}
                    actions={actions}
                    statuses={statuses}
                    onClose={() => setDialog(null)}
                />
            )}
        </AuthenticatedLayout>
    );
}
