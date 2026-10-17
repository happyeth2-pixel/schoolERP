import { BackLink, Button, Card, EmptyState, Page, Select } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { ClipboardDocumentListIcon } from '@heroicons/react/24/outline';
import { Link, router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

// The full stock movement history across every item -- the "stock movement history" report. Filter by
// type, newest first; each row already says everything section 2 of the brief asked a movement to record.

export default function Index({ movements, type, types }) {
    const { t } = useTranslation();

    const filter = (value) => {
        router.get(route('stock-movements.index'), value ? { type: value } : {}, { preserveState: true, replace: true });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">{t('inv.movement_history')}</h2>}>
            <Page
                title={t('inv.movement_history')}
                description={t('inv.movement_history_desc')}
                back={<BackLink href={route('inventory-items.index')}>{t('inv.back_to_items')}</BackLink>}
            >
                <Select value={type} onChange={(e) => filter(e.target.value)} className="max-w-xs">
                    <option value="">{t('inv.all_types')}</option>
                    {types.map((tp) => <option key={tp} value={tp}>{t(`inv.type_${tp}`)}</option>)}
                </Select>

                {movements.data.length === 0 ? (
                    <Card><EmptyState icon={ClipboardDocumentListIcon} title={t('inv.no_movements_title')}>{t('inv.no_movements_body')}</EmptyState></Card>
                ) : (
                    <Card>
                        <ul className="divide-y divide-gray-200 dark:divide-gray-700" data-testid="movement-list">
                            {movements.data.map((m) => (
                                <li key={m.id} className="py-3" data-testid="movement-row">
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <span className="text-base font-semibold text-gray-900 dark:text-gray-100">{t(`inv.type_${m.type}`)} · {m.item_name} · {m.store_name}</span>
                                        <span className={`text-lg font-bold ${m.is_increase ? 'text-emerald-700 dark:text-emerald-400' : 'text-red-700 dark:text-red-400'}`}>{m.is_increase ? '+' : '-'}{m.quantity} {m.unit}</span>
                                    </div>
                                    <p className="text-sm text-gray-600 dark:text-gray-400">{m.created_at} · {m.performed_by}{m.reason ? ` · ${m.reason}` : ''}{m.reference_number ? ` · ${m.reference_number}` : ''}</p>
                                </li>
                            ))}
                        </ul>
                    </Card>
                )}

                {(movements.prev_page_url || movements.next_page_url) && (
                    <div className="flex gap-3">
                        {movements.prev_page_url && <Button as={Link} href={movements.prev_page_url} variant="secondary" fullWidth>{t('inv.newer')}</Button>}
                        {movements.next_page_url && <Button as={Link} href={movements.next_page_url} variant="secondary" fullWidth>{t('inv.older')}</Button>}
                    </div>
                )}
            </Page>
        </AuthenticatedLayout>
    );
}
