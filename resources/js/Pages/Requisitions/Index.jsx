import { Button, Card, EmptyState, FlashMessages, Page, Select, StatusBadge } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { ClipboardDocumentCheckIcon, PlusIcon } from '@heroicons/react/24/outline';
import { Link, router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

const PRIORITY_TONE = { urgent: 'danger', normal: 'neutral', low: 'neutral' };
const STATUS_TONE = { draft: 'neutral', submitted: 'warning', approved: 'info', rejected: 'danger', partially_issued: 'info', fully_issued: 'success', completed: 'success' };

export default function Index({ requisitions, status, statuses, canCreate }) {
    const { t } = useTranslation();

    const filter = (value) => router.get(route('requisitions.index'), value ? { status: value } : {}, { preserveState: true, replace: true });

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">{t('req.list_title')}</h2>}>
            <Page
                title={t('req.list_title')}
                description={t('req.list_desc')}
                actions={canCreate && <Button as={Link} href={route('requisitions.create')} icon={PlusIcon}>{t('req.new_button')}</Button>}
            >
                <FlashMessages />
                <Select value={status} onChange={(e) => filter(e.target.value)} className="max-w-xs">
                    <option value="">{t('req.all_statuses')}</option>
                    {statuses.map((s) => <option key={s} value={s}>{t(`req.status_${s}`)}</option>)}
                </Select>

                {requisitions.data.length === 0 ? (
                    <Card><EmptyState icon={ClipboardDocumentCheckIcon} title={t('req.no_requisitions_title')}>{t('req.no_requisitions_body')}</EmptyState></Card>
                ) : (
                    <ul className="grid gap-4 lg:grid-cols-2" data-testid="requisition-list">
                        {requisitions.data.map((r) => (
                            <li key={r.id} data-testid="requisition-row" className="rounded-2xl border-2 border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-800">
                                <div className="flex flex-wrap items-start justify-between gap-2">
                                    <div className="min-w-0">
                                        <Link href={route('requisitions.show', r.id)} className="text-lg font-semibold text-gray-900 hover:underline dark:text-gray-100">{r.number}</Link>
                                        <p className="text-sm text-gray-600 dark:text-gray-400">{r.purpose} · {r.store_name}</p>
                                    </div>
                                    <StatusBadge tone={STATUS_TONE[r.status]} label={t(`req.status_${r.status}`)} />
                                </div>
                                <div className="mt-3 flex flex-wrap items-center gap-3 text-sm text-gray-700 dark:text-gray-300">
                                    <StatusBadge tone={PRIORITY_TONE[r.priority]} label={t(`req.priority_${r.priority}`)} />
                                    <span>{r.is_mine ? t('req.by_you') : t('req.by_someone', { name: r.requester })}</span>
                                    <span>{r.created_at}</span>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}

                {(requisitions.prev_page_url || requisitions.next_page_url) && (
                    <div className="flex gap-3">
                        {requisitions.prev_page_url && <Button as={Link} href={requisitions.prev_page_url} variant="secondary" fullWidth>{t('inv.newer')}</Button>}
                        {requisitions.next_page_url && <Button as={Link} href={requisitions.next_page_url} variant="secondary" fullWidth>{t('inv.older')}</Button>}
                    </div>
                )}
            </Page>
        </AuthenticatedLayout>
    );
}
