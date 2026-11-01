import SearchInput from '@/Components/SearchInput';
import { BackLink, Button, Card, EmptyState, FlashMessages, Page, Select, StatusBadge } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { ComputerDesktopIcon, PlusIcon } from '@heroicons/react/24/outline';
import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

const STATUS_TONE = { available: 'success', assigned: 'info', under_repair: 'warning', lost: 'danger', damaged: 'danger', retired: 'neutral', disposed: 'neutral' };

export default function Index({ assets, search, status, statuses, canManage }) {
    const { t } = useTranslation();
    const { auth } = usePage().props;
    const isTeacher = auth.role === 'teacher';
    const [q, setQ] = useState(search);

    const update = (params) => router.get(route('assets.index'), params, { preserveState: true, replace: true });
    const runSearch = (value) => { setQ(value); update({ search: value || undefined, status: status || undefined }); };
    const runStatus = (value) => update({ search: q || undefined, status: value || undefined });

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">{t('asset.list_title')}</h2>}>
            <Page
                title={t('asset.list_title')}
                description={t('asset.list_desc')}
                back={<BackLink big={isTeacher} href={route('dashboard')}>{t('common.back')}</BackLink>}
                actions={canManage && <Button as={Link} href={route('assets.create')} icon={PlusIcon}>{t('asset.add_button')}</Button>}
            >
                <FlashMessages />
                <div className="flex flex-wrap gap-3">
                    <SearchInput value={q} onChange={runSearch} placeholder={t('asset.search_placeholder')} />
                    <Select value={status} onChange={(e) => runStatus(e.target.value)} className="max-w-xs">
                        <option value="">{t('asset.all_statuses')}</option>
                        {statuses.map((s) => <option key={s} value={s}>{t(`asset.status_${s}`)}</option>)}
                    </Select>
                </div>

                {assets.data.length === 0 ? (
                    <Card><EmptyState icon={ComputerDesktopIcon} title={t('asset.no_assets_title')}>{t('asset.no_assets_body')}</EmptyState></Card>
                ) : (
                    <ul className="grid gap-4 lg:grid-cols-2" data-testid="asset-list">
                        {assets.data.map((a) => (
                            <li key={a.id} data-testid="asset-row" className="rounded-2xl border-2 border-stone-200 bg-white p-5 dark:border-stone-700 dark:bg-stone-800">
                                <div className="flex flex-wrap items-start justify-between gap-2">
                                    <div className="min-w-0">
                                        <Link href={route('assets.show', a.id)} className="text-lg font-semibold text-stone-900 hover:underline dark:text-stone-100">{a.name}</Link>
                                        <p className="text-sm text-stone-600 dark:text-stone-400">{a.asset_tag}{a.category_name ? ` · ${a.category_name}` : ''}</p>
                                    </div>
                                    <StatusBadge tone={STATUS_TONE[a.status]} label={t(`asset.status_${a.status}`)} />
                                </div>
                                <p className="mt-2 text-base text-stone-700 dark:text-stone-300">{a.assignee ? t('asset.with_x', { name: a.assignee }) : a.store_name ? t('asset.at_x', { name: a.store_name }) : '—'}</p>
                            </li>
                        ))}
                    </ul>
                )}

                {(assets.prev_page_url || assets.next_page_url) && (
                    <div className="flex gap-3">
                        {assets.prev_page_url && <Button as={Link} href={assets.prev_page_url} variant="secondary" fullWidth>{t('inv.newer')}</Button>}
                        {assets.next_page_url && <Button as={Link} href={assets.next_page_url} variant="secondary" fullWidth>{t('inv.older')}</Button>}
                    </div>
                )}
            </Page>
        </AuthenticatedLayout>
    );
}
