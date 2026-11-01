import { BackLink, Button, Card, EmptyState, Field, FlashMessages, Input, Page, Select, useConfirm } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { BuildingOffice2Icon, PlusIcon } from '@heroicons/react/24/outline';
import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

function AddStoreForm({ branches, isOrgWide, onDone }) {
    const { t } = useTranslation();
    const { data, setData, post, processing, errors, reset } = useForm({ name: '', code: '', branch_id: '' });

    const submit = (e) => {
        e.preventDefault();
        post(route('inventory-stores.store'), { preserveScroll: true, onSuccess: () => { reset(); onDone(); } });
    };

    return (
        <form onSubmit={submit} className="space-y-4">
            <Field id="store_name" label={t('inv.store_name')} error={errors.name}>
                <Input value={data.name} onChange={(e) => setData('name', e.target.value)} maxLength={255} autoComplete="off" />
            </Field>
            <Field id="store_code" label={t('inv.store_code')} optional error={errors.code}>
                <Input value={data.code} onChange={(e) => setData('code', e.target.value)} maxLength={50} autoComplete="off" />
            </Field>
            {isOrgWide && (
                <Field id="store_branch" label={t('inv.store_branch')} optional hint={t('inv.store_branch_hint')} error={errors.branch_id}>
                    <Select value={data.branch_id} onChange={(e) => setData('branch_id', e.target.value)}>
                        <option value="">{t('inv.all_branches')}</option>
                        {branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
                    </Select>
                </Field>
            )}
            <Button type="submit" busy={processing} disabled={data.name.trim() === ''}>{t('inv.add_store_button')}</Button>
        </form>
    );
}

export default function Index({ stores, branches, canManage, isOrgWide }) {
    const { t } = useTranslation();
    const [adding, setAdding] = useState(false);
    const [ask, confirmDialog] = useConfirm();

    const remove = async (store) => {
        if (await ask({ title: t('inv.remove_store_confirm_title', { name: store.name }), body: t('inv.remove_store_confirm_body'), confirmLabel: t('inv.remove_store_confirm_button') })) {
            router.delete(route('inventory-stores.destroy', store.id), { preserveScroll: true });
        }
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">{t('inv.stores_title')}</h2>}>
            <Page
                title={t('inv.stores_title')}
                description={t('inv.stores_desc')}
                back={<BackLink href={route('inventory-items.index')}>{t('inv.back_to_items')}</BackLink>}
                actions={canManage && !adding ? <Button icon={PlusIcon} onClick={() => setAdding(true)}>{t('inv.add_store_button')}</Button> : undefined}
            >
                <FlashMessages />

                {adding && <Card><AddStoreForm branches={branches} isOrgWide={isOrgWide} onDone={() => setAdding(false)} /></Card>}

                {stores.length === 0 ? (
                    <Card><EmptyState icon={BuildingOffice2Icon} title={t('inv.no_stores_title')}>{t('inv.no_stores_body')}</EmptyState></Card>
                ) : (
                    <ul className="grid gap-4 sm:grid-cols-2" data-testid="store-list">
                        {stores.map((s) => (
                            <li key={s.id} data-testid="store-row" className="rounded-2xl border-2 border-stone-200 bg-white p-5 dark:border-stone-700 dark:bg-stone-800">
                                <p className="text-lg font-semibold text-stone-900 dark:text-stone-100">{s.name}</p>
                                <p className="text-sm text-stone-600 dark:text-stone-400">{s.branch_name ?? t('inv.all_branches')}{s.code ? ` · ${s.code}` : ''}</p>
                                <p className="mt-2 text-base text-stone-700 dark:text-stone-300">{t('inv.store_item_count', { count: s.item_count })}</p>
                                {canManage && <Button variant="danger" size="md" className="mt-3" onClick={() => remove(s)}>{t('inv.remove_store_button')}</Button>}
                            </li>
                        ))}
                    </ul>
                )}
            </Page>
            {confirmDialog}
        </AuthenticatedLayout>
    );
}
