import { AdjustDialog, IssueDialog, ReceiveDialog, TransferDialog } from '@/Components/StockMovementDialogs';
import { BackLink, Button, Card, EmptyState, FlashMessages, Page, SectionHeader } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { ArchiveBoxIcon, ArrowsRightLeftIcon } from '@heroicons/react/24/outline';
import { Link } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

export default function Show({ item, balances, movements, stores, canManage, canReceive, canIssue, canTransfer, canAdjust }) {
    const { t } = useTranslation();
    const [dialog, setDialog] = useState(null);
    const totalOnHand = balances.reduce((sum, b) => sum + b.quantity, 0);

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">{item.name}</h2>}>
            <Page
                title={item.name}
                back={<BackLink href={route('inventory-items.index')}>{t('inv.back_to_items')}</BackLink>}
                actions={<>
                    {canReceive && <Button variant="secondary" onClick={() => setDialog('receive')}>{t('inv.receive_button')}</Button>}
                    {canIssue && <Button variant="secondary" onClick={() => setDialog('issue')}>{t('inv.issue_button')}</Button>}
                    {canTransfer && <Button variant="secondary" icon={ArrowsRightLeftIcon} onClick={() => setDialog('transfer')}>{t('inv.transfer_button')}</Button>}
                    {canAdjust && <Button variant="secondary" onClick={() => setDialog('adjust')}>{t('inv.adjust_button')}</Button>}
                </>}
            >
                <FlashMessages />

                <Card>
                    <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-base sm:grid-cols-4">
                        <div><dt className="text-sm text-stone-600 dark:text-stone-400">{t('inv.category')}</dt><dd className="font-semibold text-stone-900 dark:text-stone-100">{item.category_name ?? '—'}</dd></div>
                        <div><dt className="text-sm text-stone-600 dark:text-stone-400">{t('inv.sku')}</dt><dd className="font-semibold text-stone-900 dark:text-stone-100">{item.sku ?? '—'}</dd></div>
                        <div><dt className="text-sm text-stone-600 dark:text-stone-400">{t('inv.barcode')}</dt><dd className="font-semibold text-stone-900 dark:text-stone-100">{item.barcode ?? '—'}</dd></div>
                        <div><dt className="text-sm text-stone-600 dark:text-stone-400">{t('inv.unit')}</dt><dd className="font-semibold text-stone-900 dark:text-stone-100">{item.unit}</dd></div>
                        <div><dt className="text-sm text-stone-600 dark:text-stone-400">{t('inv.reorder_level')}</dt><dd className="font-semibold text-stone-900 dark:text-stone-100">{item.reorder_level}</dd></div>
                        <div><dt className="text-sm text-stone-600 dark:text-stone-400">{t('inv.on_hand_total')}</dt><dd className="text-xl font-bold text-stone-900 dark:text-stone-100" data-testid="total-on-hand">{totalOnHand} {item.unit}</dd></div>
                    </dl>
                    {item.description && <p className="mt-4 text-base text-stone-700 dark:text-stone-300">{item.description}</p>}
                </Card>

                <Card>
                    <SectionHeader as="h3" title={t('inv.balances_by_store')} />
                    <ul className="divide-y divide-stone-200 dark:divide-stone-700" data-testid="balances">
                        {balances.map((b) => (
                            <li key={b.store_id} className="flex items-center justify-between gap-2 py-3">
                                <span className="text-base font-medium text-stone-900 dark:text-stone-100">{b.store_name}</span>
                                <span className="text-lg font-bold text-stone-900 dark:text-stone-100">{b.quantity} {item.unit}</span>
                            </li>
                        ))}
                    </ul>
                </Card>

                <Card>
                    <SectionHeader as="h3" title={t('inv.recent_movements')} action={<Button as={Link} href={route('stock-movements.index')} variant="quiet" size="md">{t('inv.see_all_movements')}</Button>} />
                    {movements.data.length === 0 ? (
                        <EmptyState icon={ArchiveBoxIcon} title={t('inv.no_movements_title')}>{t('inv.no_movements_body')}</EmptyState>
                    ) : (
                        <ul className="divide-y divide-stone-200 dark:divide-stone-700" data-testid="movements">
                            {movements.data.map((m) => (
                                <li key={m.id} className="py-3" data-testid="movement-row">
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <span className="text-base font-semibold text-stone-900 dark:text-stone-100">{t(`inv.type_${m.type}`)} · {m.store_name}{m.related_store_name ? ` → ${m.related_store_name}` : ''}</span>
                                        <span className={`text-lg font-bold ${m.is_increase ? 'text-emerald-700 dark:text-emerald-400' : 'text-red-700 dark:text-red-400'}`}>{m.is_increase ? '+' : '-'}{m.quantity}</span>
                                    </div>
                                    <p className="text-sm text-stone-600 dark:text-stone-400">{m.created_at} · {m.performed_by}{m.reason ? ` · ${m.reason}` : ''}{m.reference_number ? ` · ${m.reference_number}` : ''}</p>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>
            </Page>

            <ReceiveDialog open={dialog === 'receive'} item={item} stores={stores} onClose={() => setDialog(null)} />
            <IssueDialog open={dialog === 'issue'} item={item} stores={stores} onClose={() => setDialog(null)} />
            <TransferDialog open={dialog === 'transfer'} item={item} stores={stores} onClose={() => setDialog(null)} />
            <AdjustDialog open={dialog === 'adjust'} item={item} stores={stores} balances={balances} onClose={() => setDialog(null)} />
        </AuthenticatedLayout>
    );
}
