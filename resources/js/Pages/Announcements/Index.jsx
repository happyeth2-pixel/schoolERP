import AttachmentGrid from '@/Components/AttachmentGrid';
import Avatar from '@/Components/Avatar';
import DateText from '@/Components/DateText';
import { BackLink, Button, EmptyState, FlashMessages, Page, StatusBadge, useConfirm } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PlusIcon, TrashIcon } from '@heroicons/react/24/outline';
import { Link, router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

export default function Index({ announcements, canManage }) {
    const { t } = useTranslation();
    const [ask, confirmDialog] = useConfirm();

    const remove = async (a) => {
        const ok = await ask({
            title: t('ann.remove_title', { title: a.title }),
            body: <p>{t('ann.remove_body')}</p>,
            confirmLabel: t('ann.remove_button'),
        });
        if (ok) router.delete(route('announcements.destroy', a.id), { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">{t('ann.page_title')}</h2>}>
            <Page
                title={t('ann.page_title')}
                description={t('ann.page_desc')}
                width="default"
                back={<BackLink href={route('dashboard')}>{t('common.back')}</BackLink>}
                actions={canManage && <Button as={Link} href={route('announcements.create')} icon={PlusIcon}>{t('ann.write_button')}</Button>}
            >
                <FlashMessages />

                {announcements.data.map((a) => (
                    <article key={a.id} data-testid="announcement" className="rounded-2xl border-2 border-stone-200 bg-white p-5 dark:border-stone-700 dark:bg-stone-800">
                        <div className="flex items-start justify-between gap-3">
                            <h3 className="text-lg font-bold text-stone-900 dark:text-stone-100">{a.title}</h3>
                            <div className="flex shrink-0 flex-wrap items-center gap-2">
                                {a.scheduled && <StatusBadge tone="warning" label={t('ann.scheduled')} />}
                                {a.editable && <Button size="md" variant="quiet" icon={TrashIcon} onClick={() => remove(a)} aria-label={t('ann.remove_button')}>{t('ann.remove')}</Button>}
                            </div>
                        </div>
                        <p className="mt-1 flex flex-wrap items-center gap-x-1.5 text-base text-stone-600 dark:text-stone-400">
                            {a.author && <Avatar src={a.author_photo_url} name={a.author} size={20} fallback="initials" />}
                            <span>
                                <DateText value={a.publish_on} />
                                {a.author ? ` · ${a.author}` : ''}
                                {` · ${a.branch_name ? a.branch_name : t('ann.all_branches')}`}
                                {canManage && ` · ${t('ann.for_audience', { audience: t(`ann.aud_${a.audience}_short`) })}`}
                            </span>
                        </p>
                        <p className="mt-3 whitespace-pre-line text-base text-stone-800 dark:text-stone-200">{a.body}</p>
                        <AttachmentGrid images={a.images} />
                    </article>
                ))}

                {announcements.data.length === 0 && (
                    <EmptyState title={t('ann.none_title')}>{canManage ? t('ann.none_body_manage') : t('ann.none_body')}</EmptyState>
                )}

                {announcements.last_page > 1 && (
                    <nav className="flex flex-wrap items-center justify-between gap-3" aria-label={t('ann.pages')}>
                        {announcements.prev_page_url ? <Button as={Link} href={announcements.prev_page_url} preserveScroll variant="secondary">{t('ann.newer')}</Button> : <span />}
                        <p className="text-base text-stone-700 dark:text-stone-300">{t('ann.page_of', { page: announcements.current_page, total: announcements.last_page })}</p>
                        {announcements.next_page_url ? <Button as={Link} href={announcements.next_page_url} preserveScroll variant="secondary">{t('ann.older')}</Button> : <span />}
                    </nav>
                )}
            </Page>
            {confirmDialog}
        </AuthenticatedLayout>
    );
}
