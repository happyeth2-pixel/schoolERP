import { NotesDialog, PersonDialog, RepairCompleteDialog, StoreDialog } from '@/Components/AssetActionDialogs';
import { BackLink, Button, Card, FlashMessages, Page, SectionHeader, StatusBadge } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Link } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

const STATUS_TONE = { available: 'success', assigned: 'info', under_repair: 'warning', lost: 'danger', damaged: 'danger', retired: 'neutral', disposed: 'neutral' };

export default function Show({ asset, events, stores, people, canManage, canViewSuppliers }) {
    const { t } = useTranslation();
    const [dialog, setDialog] = useState(null);
    const close = () => setDialog(null);

    const canAssign = canManage && asset.status === 'available';
    const canReassign = canManage && asset.status === 'assigned';
    const canReturn = canManage && asset.status === 'assigned';
    const canTransfer = canManage && asset.status === 'available';
    const canStartRepair = canManage && ['available', 'assigned'].includes(asset.status);
    const canCompleteRepair = canManage && asset.status === 'under_repair';
    const canMarkLost = canManage && ! ['lost', 'disposed'].includes(asset.status);
    const canMarkDamaged = canManage && ! ['damaged', 'disposed'].includes(asset.status);
    const canRetire = canManage && asset.status !== 'disposed' && asset.status !== 'retired';
    const canDispose = canManage && asset.status !== 'disposed';

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">{asset.name}</h2>}>
            <Page
                title={`${asset.asset_tag} · ${asset.name}`}
                back={<BackLink href={route('assets.index')}>{t('asset.back_to_list')}</BackLink>}
                actions={canManage && (
                    <>
                        {canAssign && <Button size="md" onClick={() => setDialog('assign')}>{t('asset.assign_button')}</Button>}
                        {canReassign && <Button size="md" variant="secondary" onClick={() => setDialog('reassign')}>{t('asset.reassign_button')}</Button>}
                        {canReturn && <Button size="md" variant="secondary" onClick={() => setDialog('return')}>{t('asset.return_button')}</Button>}
                        {canTransfer && <Button size="md" variant="secondary" onClick={() => setDialog('transfer')}>{t('asset.transfer_button')}</Button>}
                        {canStartRepair && <Button size="md" variant="secondary" onClick={() => setDialog('repair-start')}>{t('asset.repair_start_button')}</Button>}
                        {canCompleteRepair && <Button size="md" onClick={() => setDialog('repair-complete')}>{t('asset.repair_complete_button')}</Button>}
                    </>
                )}
            >
                <FlashMessages />

                <Card>
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p className="text-lg font-semibold text-stone-900 dark:text-stone-100">{asset.name}</p>
                            <p className="text-sm text-stone-600 dark:text-stone-400">{asset.category_name ?? '—'}{asset.serial_number ? ` · ${t('asset.serial_no', { number: asset.serial_number })}` : ''}</p>
                        </div>
                        <StatusBadge tone={STATUS_TONE[asset.status]} label={t(`asset.status_${asset.status}`)} />
                    </div>
                    <dl className="mt-4 grid grid-cols-2 gap-x-4 gap-y-2 text-base sm:grid-cols-3">
                        <div><dt className="text-sm text-stone-600 dark:text-stone-400">{t('asset.condition')}</dt><dd className="font-semibold text-stone-900 dark:text-stone-100">{t(`asset.condition_${asset.condition}`)}</dd></div>
                        <div><dt className="text-sm text-stone-600 dark:text-stone-400">{t('asset.assigned_to')}</dt><dd className="font-semibold text-stone-900 dark:text-stone-100">{asset.assignee ?? '—'}</dd></div>
                        <div><dt className="text-sm text-stone-600 dark:text-stone-400">{t('asset.location')}</dt><dd className="font-semibold text-stone-900 dark:text-stone-100">{asset.store_name ?? '—'}</dd></div>
                        <div><dt className="text-sm text-stone-600 dark:text-stone-400">{t('asset.barcode')}</dt><dd className="font-semibold text-stone-900 dark:text-stone-100">{asset.barcode ?? '—'}</dd></div>
                        <div><dt className="text-sm text-stone-600 dark:text-stone-400">{t('asset.purchase_date')}</dt><dd className="font-semibold text-stone-900 dark:text-stone-100">{asset.purchase_date ?? '—'}</dd></div>
                        <div><dt className="text-sm text-stone-600 dark:text-stone-400">{t('asset.warranty_expiry')}</dt><dd className="font-semibold text-stone-900 dark:text-stone-100">{asset.warranty_expiry ?? '—'}</dd></div>
                        <div><dt className="text-sm text-stone-600 dark:text-stone-400">{t('asset.acquisition_cost')}</dt><dd className="font-semibold text-stone-900 dark:text-stone-100">{asset.acquisition_cost ?? '—'}</dd></div>
                        <div>
                            <dt className="text-sm text-stone-600 dark:text-stone-400">{t('asset.supplier')}</dt>
                            <dd className="font-semibold text-stone-900 dark:text-stone-100">
                                {asset.supplier
                                    ? (asset.supplier_id && canViewSuppliers
                                        ? <Link href={route('suppliers.show', asset.supplier_id)} className="underline decoration-dotted hover:no-underline" title={t('asset.supplier_link_hint')}>{asset.supplier}</Link>
                                        : asset.supplier)
                                    : '—'}
                            </dd>
                        </div>
                    </dl>
                    {asset.notes && <p className="mt-4 text-base text-stone-700 dark:text-stone-300">{asset.notes}</p>}
                </Card>

                {canManage && (canMarkLost || canMarkDamaged || canRetire || canDispose) && (
                    <Card>
                        <SectionHeader as="h3" title={t('asset.other_actions')} />
                        <div className="flex flex-wrap gap-3">
                            {canMarkLost && <Button variant="danger" size="md" onClick={() => setDialog('mark-lost')}>{t('asset.mark_lost_button')}</Button>}
                            {canMarkDamaged && <Button variant="danger" size="md" onClick={() => setDialog('mark-damaged')}>{t('asset.mark_damaged_button')}</Button>}
                            {canRetire && <Button variant="secondary" size="md" onClick={() => setDialog('retire')}>{t('asset.retire_button')}</Button>}
                            {canDispose && <Button variant="danger" size="md" onClick={() => setDialog('dispose')}>{t('asset.dispose_button')}</Button>}
                        </div>
                    </Card>
                )}

                <Card>
                    <SectionHeader as="h3" title={t('asset.history_title')} />
                    {events.length === 0 ? (
                        <p className="text-base text-stone-700 dark:text-stone-300">{t('asset.no_history')}</p>
                    ) : (
                        <ul className="divide-y divide-stone-200 dark:divide-stone-700" data-testid="asset-events">
                            {events.map((e) => (
                                <li key={e.id} className="py-3" data-testid="asset-event-row">
                                    <p className="text-base font-semibold text-stone-900 dark:text-stone-100">
                                        {t(`asset.event_${e.event_type}`)}
                                        {e.to_user && ` · ${t('asset.to_x', { name: e.to_user })}`}
                                        {e.to_store && ` · ${t('asset.to_x', { name: e.to_store })}`}
                                    </p>
                                    <p className="text-sm text-stone-600 dark:text-stone-400">{e.created_at} · {e.performed_by}{e.notes ? ` · ${e.notes}` : ''}</p>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>
            </Page>

            <PersonDialog open={dialog === 'assign'} asset={asset} people={people} routeName="assets.assign" title={t('asset.assign_title', { name: asset.name })} buttonLabel={t('asset.assign_button')} onClose={close} />
            <PersonDialog open={dialog === 'reassign'} asset={asset} people={people} routeName="assets.reassign" title={t('asset.reassign_title', { name: asset.name })} buttonLabel={t('asset.reassign_button')} onClose={close} />
            <StoreDialog open={dialog === 'return'} asset={asset} stores={stores} routeName="assets.return" title={t('asset.return_title', { name: asset.name })} buttonLabel={t('asset.return_button')} onClose={close} />
            <StoreDialog open={dialog === 'transfer'} asset={asset} stores={stores} routeName="assets.transfer" title={t('asset.transfer_title', { name: asset.name })} buttonLabel={t('asset.transfer_button')} onClose={close} />
            <NotesDialog open={dialog === 'repair-start'} asset={asset} routeName="assets.repair-start" title={t('asset.repair_start_title', { name: asset.name })} buttonLabel={t('asset.repair_start_button')} onClose={close} />
            <RepairCompleteDialog open={dialog === 'repair-complete'} asset={asset} onClose={close} />
            <NotesDialog open={dialog === 'mark-lost'} asset={asset} routeName="assets.mark-lost" title={t('asset.mark_lost_title', { name: asset.name })} buttonLabel={t('asset.mark_lost_button')} tone="danger" onClose={close} />
            <NotesDialog open={dialog === 'mark-damaged'} asset={asset} routeName="assets.mark-damaged" title={t('asset.mark_damaged_title', { name: asset.name })} buttonLabel={t('asset.mark_damaged_button')} tone="danger" onClose={close} />
            <NotesDialog open={dialog === 'retire'} asset={asset} routeName="assets.retire" title={t('asset.retire_title', { name: asset.name })} buttonLabel={t('asset.retire_button')} required={false} onClose={close} />
            <NotesDialog open={dialog === 'dispose'} asset={asset} routeName="assets.dispose" title={t('asset.dispose_title', { name: asset.name })} buttonLabel={t('asset.dispose_button')} tone="danger" required={false} onClose={close} />
        </AuthenticatedLayout>
    );
}
