import { IssueDialog, ReceiveDialog, TransferDialog } from '@/Components/StockMovementDialogs';
import SearchInput from '@/Components/SearchInput';
import { Button, Card, EmptyState, FlashMessages, Page, StatusBadge } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { ArchiveBoxIcon, ArrowsRightLeftIcon, ClipboardDocumentListIcon, PlusIcon } from '@heroicons/react/24/outline';
import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

// The consumables catalog: search, low-stock/out-of-stock at a glance, and the four stock actions right
// where a storekeeper needs them -- no separate "go find the item, then go find the movement screen" hop.

export default function Index({ items, search, canManage, canReceive, canIssue, canTransfer, stores }) {
    const { t } = useTranslation();
    const [q, setQ] = useState(search);
    const [dialog, setDialog] = useState(null); // { action, item }

    const runSearch = (value) => {
        setQ(value);
        router.get(route('inventory-items.index'), value ? { search: value } : {}, { preserveState: true, replace: true });
    };

    const open = (action, item) => setDialog({ action, item: { id: item.id, name: item.name, unit: item.unit } });
    const close = () => setDialog(null);

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">{t('inv.items_title')}</h2>}>
            <Page
                title={t('inv.items_title')}
                description={t('inv.items_desc')}
                actions={<>
                    <Button as={Link} href={route('inventory-stores.index')} variant="secondary" icon={ArchiveBoxIcon}>{t('inv.manage_stores')}</Button>
                    <Button as={Link} href={route('stock-movements.index')} variant="secondary" icon={ClipboardDocumentListIcon}>{t('inv.movement_history')}</Button>
                    {canManage && <Button as={Link} href={route('inventory-items.create')} icon={PlusIcon}>{t('inv.add_item')}</Button>}
                </>}
            >
                <FlashMessages />
                <SearchInput value={q} onChange={runSearch} placeholder={t('inv.search_placeholder')} />

                {items.data.length === 0 ? (
                    <Card><EmptyState icon={ArchiveBoxIcon} title={t('inv.no_items_title')}>{t('inv.no_items_body')}</EmptyState></Card>
                ) : (
                    <ul className="grid gap-4 lg:grid-cols-2" data-testid="item-list">
                        {items.data.map((item) => (
                            <li key={item.id} data-testid="item-row" className="rounded-2xl border-2 border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-800">
                                <div className="flex flex-wrap items-start justify-between gap-2">
                                    <div className="min-w-0">
                                        <Link href={route('inventory-items.show', item.id)} className="text-lg font-semibold text-gray-900 hover:underline dark:text-gray-100">{item.name}</Link>
                                        <p className="text-sm text-gray-600 dark:text-gray-400">{[item.category_name, item.sku].filter(Boolean).join(' · ') || '—'}</p>
                                    </div>
                                    {item.out_of_stock ? (
                                        <StatusBadge tone="danger" label={t('inv.out_of_stock')} />
                                    ) : item.low_stock ? (
                                        <StatusBadge tone="warning" label={t('inv.low_stock')} />
                                    ) : (
                                        <StatusBadge tone="success" label={t('inv.in_stock')} />
                                    )}
                                </div>
                                <p className="mt-2 text-2xl font-bold text-gray-900 dark:text-gray-100" data-testid="on-hand">{item.on_hand} <span className="text-base font-normal text-gray-600 dark:text-gray-400">{item.unit}</span></p>
                                <div className="mt-4 flex flex-wrap gap-2">
                                    {canReceive && <Button size="md" variant="secondary" onClick={() => open('receive', item)}>{t('inv.receive_button')}</Button>}
                                    {canIssue && <Button size="md" variant="secondary" onClick={() => open('issue', item)}>{t('inv.issue_button')}</Button>}
                                    {canTransfer && <Button size="md" variant="secondary" icon={ArrowsRightLeftIcon} onClick={() => open('transfer', item)}>{t('inv.transfer_button')}</Button>}
                                </div>
                            </li>
                        ))}
                    </ul>
                )}

                {(items.prev_page_url || items.next_page_url) && (
                    <div className="flex gap-3">
                        {items.prev_page_url && <Button as={Link} href={items.prev_page_url} variant="secondary" fullWidth>{t('inv.newer')}</Button>}
                        {items.next_page_url && <Button as={Link} href={items.next_page_url} variant="secondary" fullWidth>{t('inv.older')}</Button>}
                    </div>
                )}
            </Page>

            {/* keyed by item id: useForm only captures its initial values once, so a fresh instance is
                needed whenever a DIFFERENT row's dialog opens, or it would keep submitting against
                whichever item was selected first */}
            <ReceiveDialog key={`receive-${dialog?.item?.id ?? 'none'}`} open={dialog?.action === 'receive'} item={dialog?.item} stores={stores} onClose={close} />
            <IssueDialog key={`issue-${dialog?.item?.id ?? 'none'}`} open={dialog?.action === 'issue'} item={dialog?.item} stores={stores} onClose={close} />
            <TransferDialog key={`transfer-${dialog?.item?.id ?? 'none'}`} open={dialog?.action === 'transfer'} item={dialog?.item} stores={stores} onClose={close} />
        </AuthenticatedLayout>
    );
}
