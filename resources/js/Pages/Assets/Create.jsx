import DateInput from '@/Components/DateInput';
import { BackLink, Button, ChoiceGroup, Field, FlashMessages, Input, Page, Select, TextArea } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

const OTHER_SUPPLIER = '__other__';

export default function Create({ categories, stores, suppliers }) {
    const { t } = useTranslation();
    const { data, setData, post, processing, errors, clearErrors } = useForm({
        name: '', category_id: '', serial_number: '', barcode: '', purchase_date: '', acquisition_cost: '',
        supplier: '', supplier_id: '', supplier_choice: '',
        store_id: stores.length === 1 ? String(stores[0].id) : '', condition: 'good', warranty_expiry: '', notes: '',
    });

    const setSupplierChoice = (value) => {
        setData((prev) => ({
            ...prev,
            supplier_choice: value,
            supplier_id: value !== '' && value !== OTHER_SUPPLIER ? value : '',
            supplier: value === OTHER_SUPPLIER ? prev.supplier : '',
        }));
    };

    const submit = (e) => {
        e.preventDefault();
        post(route('assets.store'));
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">{t('asset.add_button')}</h2>}>
            <Page title={t('asset.add_button')} description={t('asset.add_desc')} width="narrow" back={<BackLink href={route('assets.index')}>{t('asset.back_to_list')}</BackLink>}>
                <FlashMessages />
                <form onSubmit={submit} className="space-y-6">
                    <Field id="asset_name" label={t('asset.name')} error={errors.name}>
                        <Input value={data.name} onChange={(e) => { clearErrors('name'); setData('name', e.target.value); }} maxLength={255} autoComplete="off" />
                    </Field>
                    <Field id="asset_category" label={t('asset.category')} optional error={errors.category_id}>
                        <Select value={data.category_id} onChange={(e) => setData('category_id', e.target.value)}>
                            <option value="">{t('inv.no_category')}</option>
                            {categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                        </Select>
                    </Field>
                    <Field id="asset_store" label={t('asset.initial_store')} error={errors.store_id}>
                        <Select value={data.store_id} onChange={(e) => setData('store_id', e.target.value)}>
                            <option value="">--</option>
                            {stores.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                        </Select>
                    </Field>
                    <Field id="asset_serial" label={t('asset.serial_number')} optional error={errors.serial_number}>
                        <Input value={data.serial_number} onChange={(e) => setData('serial_number', e.target.value)} maxLength={255} autoComplete="off" />
                    </Field>
                    <Field id="asset_barcode" label={t('asset.barcode')} optional error={errors.barcode}>
                        <Input value={data.barcode} onChange={(e) => setData('barcode', e.target.value)} maxLength={255} autoComplete="off" />
                    </Field>
                    <Field id="asset_purchase_date" label={t('asset.purchase_date')} optional error={errors.purchase_date}>
                        <DateInput value={data.purchase_date} onChange={(v) => setData('purchase_date', v)} />
                    </Field>
                    <Field id="asset_cost" label={t('asset.acquisition_cost')} optional error={errors.acquisition_cost}>
                        <Input inputMode="decimal" value={data.acquisition_cost} onChange={(e) => setData('acquisition_cost', e.target.value)} autoComplete="off" />
                    </Field>
                    <Field id="asset_supplier" label={t('asset.supplier_pick')} optional error={errors.supplier_id}>
                        <Select value={data.supplier_choice} onChange={(e) => setSupplierChoice(e.target.value)}>
                            <option value="">{t('asset.supplier_none')}</option>
                            {suppliers.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                            <option value={OTHER_SUPPLIER}>{t('asset.supplier_other')}</option>
                        </Select>
                    </Field>
                    {data.supplier_choice === OTHER_SUPPLIER && (
                        <Field id="asset_supplier_other" label={t('asset.supplier_other_name')} optional error={errors.supplier}>
                            <Input value={data.supplier} onChange={(e) => setData('supplier', e.target.value)} maxLength={255} autoComplete="off" />
                        </Field>
                    )}
                    <ChoiceGroup
                        heading={t('asset.condition')}
                        layout="segmented"
                        value={data.condition}
                        onChange={(v) => setData('condition', v)}
                        options={[
                            { value: 'good', label: t('asset.condition_good') },
                            { value: 'fair', label: t('asset.condition_fair') },
                            { value: 'poor', label: t('asset.condition_poor') },
                        ]}
                    />
                    <Field id="asset_warranty" label={t('asset.warranty_expiry')} optional error={errors.warranty_expiry}>
                        <DateInput value={data.warranty_expiry} onChange={(v) => setData('warranty_expiry', v)} />
                    </Field>
                    <Field id="asset_notes" label={t('asset.notes')} optional error={errors.notes}>
                        <TextArea id="asset_notes" value={data.notes} onChange={(e) => setData('notes', e.target.value)} />
                    </Field>
                    <Button type="submit" fullWidth busy={processing} disabled={data.name.trim() === '' || data.store_id === ''}>{t('asset.save_button')}</Button>
                </form>
            </Page>
        </AuthenticatedLayout>
    );
}
