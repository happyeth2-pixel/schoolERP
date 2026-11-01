import CalendarEventDialog from '@/Components/CalendarEventDialog';
import DateText from '@/Components/DateText';
import { BackLink, Button, Card, Disclosure, EmptyState, FlashMessages, Page, StatusBadge, useConfirm } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { CalendarDaysIcon, PencilIcon, PlusIcon, TrashIcon } from '@heroicons/react/24/outline';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

const TYPE_TONE = { holiday: 'success', exam: 'danger', meeting: 'info', activity: 'info', deadline: 'warning', other: 'neutral' };

function EventCard({ event, canManage, onEdit, onRemove, t }) {
    return (
        <article data-testid="calendar-event" className="rounded-2xl border-2 border-stone-200 bg-white p-5 dark:border-stone-700 dark:bg-stone-800">
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <h3 className="text-lg font-bold text-stone-900 dark:text-stone-100">{event.title}</h3>
                    <p className="mt-1 text-base text-stone-600 dark:text-stone-400">
                        <DateText value={event.start_date} />
                        {event.end_date && event.end_date !== event.start_date && <> &ndash; <DateText value={event.end_date} /></>}
                        {` · ${event.branch_name ?? t('calendar.all_branches')}`}
                        {canManage && ` · ${t('calendar.for_audience', { audience: event.audience_label })}`}
                        {event.creator ? ` · ${event.creator}` : ''}
                    </p>
                </div>
                <div className="flex shrink-0 flex-wrap items-center gap-2">
                    <StatusBadge tone={TYPE_TONE[event.event_type] ?? 'neutral'} label={event.event_type_label} />
                    {event.editable && (
                        <>
                            <Button size="md" variant="quiet" icon={PencilIcon} onClick={() => onEdit(event)} aria-label={t('calendar.edit_button')} />
                            <Button size="md" variant="quiet" icon={TrashIcon} onClick={() => onRemove(event)} aria-label={t('calendar.remove_button')} />
                        </>
                    )}
                </div>
            </div>
            {event.description && <p className="mt-3 whitespace-pre-line text-base text-stone-800 dark:text-stone-200">{event.description}</p>}
        </article>
    );
}

export default function Index({ upcoming, past, canManage, eventTypes, audiences, branches, canPostSchoolWide }) {
    const { t } = useTranslation();
    const [dialog, setDialog] = useState(null); // null | 'new' | an event object
    const [ask, confirmDialog] = useConfirm();

    const remove = async (event) => {
        const ok = await ask({
            title: t('calendar.remove_title', { title: event.title }),
            body: <p>{t('calendar.remove_body')}</p>,
            confirmLabel: t('calendar.remove_button'),
            tone: 'danger',
        });
        if (ok) router.delete(route('calendar.destroy', event.id), { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">{t('calendar.page_title')}</h2>}>
            <Page
                title={t('calendar.page_title')}
                description={t('calendar.page_desc')}
                width="default"
                back={<BackLink href={route('dashboard')}>{t('common.back')}</BackLink>}
                actions={canManage && <Button icon={PlusIcon} onClick={() => setDialog('new')}>{t('calendar.add_button')}</Button>}
            >
                <FlashMessages />

                {upcoming.length === 0 ? (
                    <Card><EmptyState icon={CalendarDaysIcon} title={t('calendar.none_title')}>{canManage ? t('calendar.none_body_manage') : t('calendar.none_body')}</EmptyState></Card>
                ) : (
                    <div className="space-y-4">
                        {upcoming.map((e) => <EventCard key={e.id} event={e} canManage={canManage} onEdit={setDialog} onRemove={remove} t={t} />)}
                    </div>
                )}

                {past.length > 0 && (
                    <Disclosure title={t('calendar.past_events', { count: past.length })} data-testid="past-events">
                        <div className="space-y-4">
                            {past.map((e) => <EventCard key={e.id} event={e} canManage={canManage} onEdit={setDialog} onRemove={remove} t={t} />)}
                        </div>
                    </Disclosure>
                )}
            </Page>
            {canManage && (
                <CalendarEventDialog
                    key={dialog === 'new' || dialog === null ? 'new' : `edit-${dialog.id}`}
                    open={dialog !== null}
                    event={dialog === 'new' ? null : dialog}
                    eventTypes={eventTypes}
                    audiences={audiences}
                    branches={branches}
                    canPostSchoolWide={canPostSchoolWide}
                    onClose={() => setDialog(null)}
                />
            )}
            {confirmDialog}
        </AuthenticatedLayout>
    );
}
