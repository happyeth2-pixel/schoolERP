import Modal from '@/Components/Modal';
import { Button, Card, EmptyState, Field, FlashMessages, Page, Select, StatusBadge } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { ClipboardDocumentListIcon, PlusIcon } from '@heroicons/react/24/outline';
import { Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

function StartDialog({ open, stores, onClose }) {
    const { t } = useTranslation();
    const { data, setData, post, processing, errors, reset } = useForm({ store_id: '' });

    const submit = (e) => {
        e.preventDefault();
        post(route('stocktakes.store'), { onSuccess: () => { reset(); onClose(); } });
    };

    return (
        <Modal show={open} onClose={onClose} maxWidth="lg">
            {open && (
                <form onSubmit={submit} className="space-y-6 p-6" data-testid="start-stocktake-form">
                    <h2 className="text-2xl font-bold text-gray-900 dark:text-gray-100">{t('stocktake.start_title')}</h2>
                    <Field id="stocktake_store" label={t('stocktake.store')} hint={t('stocktake.store_hint')} error={errors.store_id}>
                        <Select value={data.store_id} onChange={(e) => setData('store_id', e.target.value)}>
                            <option value="">--</option>
                            {stores.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                        </Select>
                    </Field>
                    <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        <Button type="button" variant="secondary" onClick={onClose}>{t('common.cancel')}</Button>
                        <Button type="submit" busy={processing} disabled={!data.store_id}>{t('stocktake.start_button')}</Button>
                    </div>
                </form>
            )}
        </Modal>
    );
}

export default function Index({ stocktakes, stores, canManage }) {
    const { t } = useTranslation();
    const [starting, setStarting] = useState(false);

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">{t('stocktake.list_title')}</h2>}>
            <Page
                title={t('stocktake.list_title')}
                description={t('stocktake.list_desc')}
                actions={canManage && <Button icon={PlusIcon} onClick={() => setStarting(true)}>{t('stocktake.start_button')}</Button>}
            >
                <FlashMessages />

                {stocktakes.data.length === 0 ? (
                    <Card><EmptyState icon={ClipboardDocumentListIcon} title={t('stocktake.no_stocktakes_title')}>{t('stocktake.no_stocktakes_body')}</EmptyState></Card>
                ) : (
                    <ul className="grid gap-4 lg:grid-cols-2" data-testid="stocktake-list">
                        {stocktakes.data.map((s) => (
                            <li key={s.id} data-testid="stocktake-row" className="rounded-2xl border-2 border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-800">
                                <div className="flex flex-wrap items-start justify-between gap-2">
                                    <div className="min-w-0">
                                        <Link href={route('stocktakes.show', s.id)} className="text-lg font-semibold text-gray-900 hover:underline dark:text-gray-100">{s.store_name}</Link>
                                        <p className="text-sm text-gray-600 dark:text-gray-400">{t('stocktake.item_count', { count: s.line_count })} · {t('stocktake.started_by_x', { name: s.started_by, date: s.started_at })}</p>
                                    </div>
                                    <StatusBadge tone={s.status === 'completed' ? 'success' : 'warning'} label={t(`stocktake.status_${s.status}`)} />
                                </div>
                            </li>
                        ))}
                    </ul>
                )}

                {(stocktakes.prev_page_url || stocktakes.next_page_url) && (
                    <div className="flex gap-3">
                        {stocktakes.prev_page_url && <Button as={Link} href={stocktakes.prev_page_url} variant="secondary" fullWidth>{t('inv.newer')}</Button>}
                        {stocktakes.next_page_url && <Button as={Link} href={stocktakes.next_page_url} variant="secondary" fullWidth>{t('inv.older')}</Button>}
                    </div>
                )}
            </Page>

            <StartDialog open={starting} stores={stores} onClose={() => setStarting(false)} />
        </AuthenticatedLayout>
    );
}
