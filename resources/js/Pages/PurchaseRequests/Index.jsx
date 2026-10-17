import { Button, Card, EmptyState, FlashMessages, Page, Select, StatusBadge } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { ClipboardDocumentCheckIcon, PlusIcon } from '@heroicons/react/24/outline';
import { Link, router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

const money = (n) => Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const PRIORITY_TONE = { urgent: 'danger', normal: 'neutral', low: 'neutral' };
const STATUS_TONE = { draft: 'neutral', submitted: 'warning', approved: 'success', rejected: 'danger' };

export default function Index({ requests, status, statuses, canCreate }) {
    const { t } = useTranslation();

    const filter = (value) => router.get(route('purchase-requests.index'), value ? { status: value } : {}, { preserveState: true, replace: true });

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">{t('pr.list_title')}</h2>}>
            <Page
                title={t('pr.list_title')}
                description={t('pr.list_desc')}
                actions={canCreate && <Button as={Link} href={route('purchase-requests.create')} icon={PlusIcon}>{t('pr.new_button')}</Button>}
            >
                <FlashMessages />
                <Select value={status} onChange={(e) => filter(e.target.value)} className="max-w-xs">
                    <option value="">{t('pr.all_statuses')}</option>
                    {statuses.map((s) => <option key={s} value={s}>{t(`pr.status_${s}`)}</option>)}
                </Select>

                {requests.data.length === 0 ? (
                    <Card><EmptyState icon={ClipboardDocumentCheckIcon} title={t('pr.no_requests_title')}>{t('pr.no_requests_body')}</EmptyState></Card>
                ) : (
                    <ul className="grid gap-4 lg:grid-cols-2" data-testid="pr-list">
                        {requests.data.map((r) => (
                            <li key={r.id} data-testid="pr-row" className="rounded-2xl border-2 border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-800">
                                <div className="flex flex-wrap items-start justify-between gap-2">
                                    <div className="min-w-0">
                                        <Link href={route('purchase-requests.show', r.id)} className="text-lg font-semibold text-gray-900 hover:underline dark:text-gray-100">{r.number}</Link>
                                        <p className="text-sm text-gray-600 dark:text-gray-400">{r.purpose}</p>
                                    </div>
                                    <StatusBadge tone={STATUS_TONE[r.status]} label={t(`pr.status_${r.status}`)} />
                                </div>
                                <div className="mt-3 flex flex-wrap items-center gap-3 text-sm text-gray-700 dark:text-gray-300">
                                    <StatusBadge tone={PRIORITY_TONE[r.priority]} label={t(`pr.priority_${r.priority}`)} />
                                    <span>{r.is_mine ? t('pr.by_you') : t('pr.by_someone', { name: r.requester })}</span>
                                    {r.estimated_total > 0 && <span>{money(r.estimated_total)} ETB</span>}
                                </div>
                            </li>
                        ))}
                    </ul>
                )}

                {(requests.prev_page_url || requests.next_page_url) && (
                    <div className="flex gap-3">
                        {requests.prev_page_url && <Button as={Link} href={requests.prev_page_url} variant="secondary" fullWidth>{t('inv.newer')}</Button>}
                        {requests.next_page_url && <Button as={Link} href={requests.next_page_url} variant="secondary" fullWidth>{t('inv.older')}</Button>}
                    </div>
                )}
            </Page>
        </AuthenticatedLayout>
    );
}
