import { BackLink, Button, Card, EmptyState, FlashMessages, Page, Select, StatusBadge } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { ClipboardDocumentListIcon } from '@heroicons/react/24/outline';
import { Link, router, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

const money = (n) => Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const STATUS_TONE = { draft: 'neutral', sent: 'warning', partially_received: 'warning', received: 'success', cancelled: 'danger' };

export default function Index({ orders, status, statuses }) {
    const { t } = useTranslation();
    const { auth } = usePage().props;

    const filter = (value) => router.get(route('purchase-orders.index'), value ? { status: value } : {}, { preserveState: true, replace: true });

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">{t('purchase_order.list_title')}</h2>}>
            <Page title={t('purchase_order.list_title')} description={t('purchase_order.list_desc')} back={<BackLink big={auth.role === 'teacher'} href={route('dashboard')}>{t('common.back')}</BackLink>}>
                <FlashMessages />
                <Select value={status} onChange={(e) => filter(e.target.value)} className="max-w-xs">
                    <option value="">{t('pr.all_statuses')}</option>
                    {statuses.map((s) => <option key={s} value={s}>{t(`purchase_order.status_${s}`)}</option>)}
                </Select>

                {orders.data.length === 0 ? (
                    <Card><EmptyState icon={ClipboardDocumentListIcon} title={t('purchase_order.no_orders_title')}>{t('purchase_order.no_orders_body')}</EmptyState></Card>
                ) : (
                    <ul className="grid gap-4 lg:grid-cols-2" data-testid="po-list">
                        {orders.data.map((po) => (
                            <li key={po.id} data-testid="po-row" className="rounded-2xl border-2 border-stone-200 bg-white p-5 dark:border-stone-700 dark:bg-stone-800">
                                <div className="flex flex-wrap items-start justify-between gap-2">
                                    <div className="min-w-0">
                                        <Link href={route('purchase-orders.show', po.id)} className="text-lg font-semibold text-stone-900 hover:underline dark:text-stone-100">{po.number}</Link>
                                        <p className="text-sm text-stone-600 dark:text-stone-400">{po.supplier}</p>
                                    </div>
                                    <StatusBadge tone={STATUS_TONE[po.status]} label={t(`purchase_order.status_${po.status}`)} />
                                </div>
                                <p className="mt-3 text-base font-semibold text-stone-900 dark:text-stone-100">{money(po.total)} ETB</p>
                                <p className="text-sm text-stone-600 dark:text-stone-400">{po.order_date}</p>
                            </li>
                        ))}
                    </ul>
                )}

                {(orders.prev_page_url || orders.next_page_url) && (
                    <div className="flex gap-3">
                        {orders.prev_page_url && <Button as={Link} href={orders.prev_page_url} variant="secondary" fullWidth>{t('inv.newer')}</Button>}
                        {orders.next_page_url && <Button as={Link} href={orders.next_page_url} variant="secondary" fullWidth>{t('inv.older')}</Button>}
                    </div>
                )}
            </Page>
        </AuthenticatedLayout>
    );
}
