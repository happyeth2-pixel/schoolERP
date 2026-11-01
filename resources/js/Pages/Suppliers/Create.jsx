import { BackLink, Button, Field, FlashMessages, Input, Page, TextArea } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

export default function Create() {
    const { t } = useTranslation();
    const { data, setData, post, processing, errors, clearErrors } = useForm({
        name: '', contact_person: '', phone: '', email: '', address: '',
        tax_number: '', bank_name: '', bank_account_name: '', bank_account_number: '', notes: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('suppliers.store'));
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">{t('supplier.add_button')}</h2>}>
            <Page title={t('supplier.add_button')} description={t('supplier.add_desc')} width="narrow" back={<BackLink href={route('suppliers.index')}>{t('supplier.back_to_list')}</BackLink>}>
                <FlashMessages />
                <form onSubmit={submit} className="space-y-6">
                    <Field id="supplier_name" label={t('supplier.name')} error={errors.name}>
                        <Input value={data.name} onChange={(e) => { clearErrors('name'); setData('name', e.target.value); }} maxLength={255} autoComplete="off" />
                    </Field>
                    <Field id="supplier_contact" label={t('supplier.contact_person')} optional error={errors.contact_person}>
                        <Input value={data.contact_person} onChange={(e) => setData('contact_person', e.target.value)} maxLength={255} autoComplete="off" />
                    </Field>
                    <Field id="supplier_phone" label={t('supplier.phone')} optional error={errors.phone}>
                        <Input type="tel" value={data.phone} onChange={(e) => setData('phone', e.target.value)} maxLength={50} autoComplete="off" />
                    </Field>
                    <Field id="supplier_email" label={t('supplier.email')} optional error={errors.email}>
                        <Input type="email" value={data.email} onChange={(e) => setData('email', e.target.value)} maxLength={255} autoComplete="off" />
                    </Field>
                    <Field id="supplier_address" label={t('supplier.address')} optional error={errors.address}>
                        <TextArea id="supplier_address" value={data.address} onChange={(e) => setData('address', e.target.value)} />
                    </Field>
                    <Field id="supplier_tax" label={t('supplier.tax_number')} optional error={errors.tax_number}>
                        <Input value={data.tax_number} onChange={(e) => setData('tax_number', e.target.value)} maxLength={100} autoComplete="off" />
                    </Field>
                    <Field id="supplier_bank_name" label={t('supplier.bank_name')} optional error={errors.bank_name}>
                        <Input value={data.bank_name} onChange={(e) => setData('bank_name', e.target.value)} maxLength={255} autoComplete="off" />
                    </Field>
                    <Field id="supplier_bank_account_name" label={t('supplier.bank_account_name')} optional error={errors.bank_account_name}>
                        <Input value={data.bank_account_name} onChange={(e) => setData('bank_account_name', e.target.value)} maxLength={255} autoComplete="off" />
                    </Field>
                    <Field id="supplier_bank_account_number" label={t('supplier.bank_account_number')} optional error={errors.bank_account_number}>
                        <Input value={data.bank_account_number} onChange={(e) => setData('bank_account_number', e.target.value)} maxLength={100} autoComplete="off" />
                    </Field>
                    <Field id="supplier_notes" label={t('supplier.notes')} optional error={errors.notes}>
                        <TextArea id="supplier_notes" value={data.notes} onChange={(e) => setData('notes', e.target.value)} />
                    </Field>
                    <Button type="submit" fullWidth busy={processing} disabled={data.name.trim() === ''}>{t('supplier.save_button')}</Button>
                </form>
            </Page>
        </AuthenticatedLayout>
    );
}
