import { BackLink, Button, Card, EmptyState, Page, StatusBadge } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { BellIcon, CheckIcon } from '@heroicons/react/24/outline';
import { Link, router, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

// Messages from the school. What is new is unmistakable (a coloured card, a "New" badge, and a count at the top);
// what has been read fades to plain. Same data, same routes: marking read only ever touches the person's own messages.

export default function Index({ notificationList }) {
    const { t } = useTranslation();
    const { auth } = usePage().props;
    const isParent = auth.role === 'parent';
    const list = notificationList.data;
    const unread = list.filter((n) => !n.read_at).length;
    const markRead = (id) => router.post(route('notifications.read', id), {}, { preserveScroll: true });
    const title = t('parent.msg_page_title');

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">{title}</h2>}>
            <Page
                title={title}
                description={unread > 0 ? t('parent.msg_page_new', { count: unread }) : undefined}
                width="default"
                back={<BackLink big={isParent} href={route('dashboard')}>{isParent ? t('parent.back_home') : t('common.back')}</BackLink>}
            >
                {unread > 0 && (
                    <Button variant="secondary" fullWidth icon={CheckIcon} onClick={() => router.post(route('notifications.read-all'), {}, { preserveScroll: true })}>{t('parent.msg_mark_all')}</Button>
                )}

                {list.length === 0 && <Card><EmptyState icon={BellIcon} title={t('parent.msg_none')}>{t('parent.msg_none_hint')}</EmptyState></Card>}

                <ul className="space-y-3" data-testid="messages">
                    {list.map((n) => (
                        <li key={n.id} data-unread={!n.read_at} className={`rounded-2xl border-2 p-4 ${n.read_at ? 'border-stone-200 bg-white dark:border-stone-700 dark:bg-stone-800' : 'border-sky-400 bg-sky-50 dark:border-sky-700 dark:bg-sky-900/20'}`}>
                            {!n.read_at && <StatusBadge tone="info" label={t('parent.msg_new')} className="mb-2" />}
                            <p className="text-lg font-semibold text-stone-900 dark:text-stone-100">{n.title}</p>
                            <p className="mt-1 text-base text-stone-800 dark:text-stone-200">{n.body}</p>
                            <div className="mt-3 flex flex-wrap items-center justify-between gap-3">
                                <p className="text-sm text-stone-600 dark:text-stone-400">{n.created_at}</p>
                                <div className="flex flex-wrap gap-3">
                                    {n.action_url && <Button as={Link} href={n.action_url} size="md" data-testid="notification-action">{t('parent.msg_open')}</Button>}
                                    {!n.read_at && <Button variant="secondary" size="md" onClick={() => markRead(n.id)}>{t('parent.msg_mark_one')}</Button>}
                                </div>
                            </div>
                        </li>
                    ))}
                </ul>

                {(notificationList.prev_page_url || notificationList.next_page_url) && (
                    <div className="flex gap-3">
                        {notificationList.prev_page_url && <Button as={Link} href={notificationList.prev_page_url} variant="secondary" fullWidth>{t('parent.msg_newer')}</Button>}
                        {notificationList.next_page_url && <Button as={Link} href={notificationList.next_page_url} variant="secondary" fullWidth>{t('parent.msg_older')}</Button>}
                    </div>
                )}
            </Page>
        </AuthenticatedLayout>
    );
}
