import Avatar from '@/Components/Avatar';
import { BackLink, Card, EmptyState, Page } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { ChatBubbleLeftRightIcon } from '@heroicons/react/24/outline';
import { Link } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

// One list of every private conversation this account can reach -- a parent's own children, a homeroom
// teacher's own class, or (for a branch admin/owner/auditor) whichever students already have a conversation
// in their scope. Tapping a row opens that student's thread (Messages/Thread).

export default function Inbox({ conversations }) {
    const { t } = useTranslation();
    const title = t('messages.inbox_title');

    return (
        <AuthenticatedLayout header={<h2 className="truncate text-lg font-semibold text-stone-800 dark:text-stone-200">{title}</h2>}>
            <Page title={title} description={t('messages.inbox_desc')} width="narrow" back={<BackLink href={route('dashboard')}>{t('common.back')}</BackLink>}>
                {conversations.length === 0 ? (
                    <Card><EmptyState icon={ChatBubbleLeftRightIcon} title={t('messages.inbox_empty_title')}>{t('messages.inbox_empty_body')}</EmptyState></Card>
                ) : (
                    <Card>
                        <ul className="divide-y divide-stone-200 dark:divide-stone-700" data-testid="conversation-list">
                            {conversations.map((c) => (
                                <li key={c.student_id}>
                                    <Link
                                        href={route('students.messages.index', c.student_id)}
                                        className="flex items-center justify-between gap-3 py-4 hover:bg-stone-50 dark:hover:bg-stone-900/40"
                                        data-testid="conversation-row"
                                    >
                                        <div className="flex min-w-0 items-center gap-3">
                                            {c.contact && (
                                                <Avatar
                                                    src={c.contact.photo_url}
                                                    name={c.contact.name ?? ''}
                                                    size={44}
                                                    fallback="initials"
                                                    className="shrink-0"
                                                />
                                            )}
                                            <div className="min-w-0">
                                                {c.contact ? (
                                                    <>
                                                        <p className="text-lg font-semibold text-stone-900 dark:text-stone-100" data-testid="conversation-contact">
                                                            {c.contact.role === 'homeroom_teacher' ? c.contact.name : t('messages.school_office_label')}
                                                        </p>
                                                        <p className="text-sm text-brand-700 dark:text-brand-400">
                                                            {c.contact.role === 'homeroom_teacher' ? t('messages.role_homeroom_teacher') : t('messages.no_homeroom_teacher_yet')}
                                                        </p>
                                                        <p className="text-sm text-stone-500 dark:text-stone-400">{t('messages.for_student', { name: c.student_name })}</p>
                                                    </>
                                                ) : (
                                                    <p className="text-lg font-semibold text-stone-900 dark:text-stone-100">{c.student_name}</p>
                                                )}
                                                {c.last_message ? (
                                                    <p className="truncate text-base text-stone-600 dark:text-stone-400">
                                                        <span className="font-medium">{c.last_sender}:</span> {c.last_message}
                                                    </p>
                                                ) : (
                                                    <p className="text-base italic text-stone-500 dark:text-stone-500">{t('messages.no_messages_yet')}</p>
                                                )}
                                            </div>
                                        </div>
                                        {c.last_at && <span className="shrink-0 text-sm text-stone-500 dark:text-stone-400">{c.last_at}</span>}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </Card>
                )}
            </Page>
        </AuthenticatedLayout>
    );
}
