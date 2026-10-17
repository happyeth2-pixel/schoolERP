import { Button, Field, FlashMessages, Input, Page, Select, TextArea } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

const UNIT_SUGGESTIONS = ['pcs', 'box', 'ream', 'pack', 'set', 'kg', 'litre', 'roll'];

export default function Create({ categories }) {
    const { t } = useTranslation();
    const { data, setData, post, processing, errors, clearErrors } = useForm({
        category_id: '', name: '', sku: '', barcode: '', unit: 'pcs', description: '', min_stock_level: '0', reorder_level: '0',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('inventory-items.store'));
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">{t('inv.add_item')}</h2>}>
            <Page title={t('inv.add_item')} description={t('inv.add_item_desc')} width="narrow">
                <FlashMessages />
                <form onSubmit={submit} className="space-y-6">
                    <Field id="item_name" label={t('inv.name')} error={errors.name}>
                        <Input value={data.name} onChange={(e) => { clearErrors('name'); setData('name', e.target.value); }} maxLength={255} autoComplete="off" />
                    </Field>
                    <Field id="item_category" label={t('inv.category')} optional error={errors.category_id}>
                        <Select value={data.category_id} onChange={(e) => setData('category_id', e.target.value)}>
                            <option value="">{t('inv.no_category')}</option>
                            {categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                        </Select>
                    </Field>
                    <Field id="item_unit" label={t('inv.unit')} hint={t('inv.unit_hint')} error={errors.unit}>
                        <Input list="unit-suggestions" value={data.unit} onChange={(e) => setData('unit', e.target.value)} maxLength={30} autoComplete="off" />
                    </Field>
                    <datalist id="unit-suggestions">{UNIT_SUGGESTIONS.map((u) => <option key={u} value={u} />)}</datalist>
                    <Field id="item_sku" label={t('inv.sku')} optional hint={t('inv.sku_hint')} error={errors.sku}>
                        <Input value={data.sku} onChange={(e) => { clearErrors('sku'); setData('sku', e.target.value); }} maxLength={100} autoComplete="off" />
                    </Field>
                    <Field id="item_barcode" label={t('inv.barcode')} optional error={errors.barcode}>
                        <Input value={data.barcode} onChange={(e) => { clearErrors('barcode'); setData('barcode', e.target.value); }} maxLength={100} autoComplete="off" />
                    </Field>
                    <Field id="item_description" label={t('inv.description')} optional error={errors.description}>
                        <TextArea id="item_description" value={data.description} onChange={(e) => setData('description', e.target.value)} maxLength={2000} />
                    </Field>
                    <Field id="item_min" label={t('inv.min_stock_level')} hint={t('inv.min_stock_hint')} error={errors.min_stock_level}>
                        <Input inputMode="numeric" value={data.min_stock_level} onChange={(e) => setData('min_stock_level', e.target.value)} autoComplete="off" />
                    </Field>
                    <Field id="item_reorder" label={t('inv.reorder_level')} hint={t('inv.reorder_hint')} error={errors.reorder_level}>
                        <Input inputMode="numeric" value={data.reorder_level} onChange={(e) => setData('reorder_level', e.target.value)} autoComplete="off" />
                    </Field>
                    <Button type="submit" fullWidth busy={processing} disabled={data.name.trim() === ''}>{t('inv.save_item')}</Button>
                </form>
            </Page>
        </AuthenticatedLayout>
    );
}
