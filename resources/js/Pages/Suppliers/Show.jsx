import { BackLink, Button, Card, Field, FlashMessages, Input, Page, SectionHeader, TextArea, useConfirm } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { router, useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

export default function Show({ supplier, canManage }) {
    const { t } = useTranslation();
    const { data, setData, patch, processing, errors } = useForm({
        name: supplier.name, contact_person: supplier.contact_person ?? '', phone: supplier.phone ?? '', email: supplier.email ?? '',
        address: supplier.address ?? '', tax_number: supplier.tax_number ?? '', bank_name: supplier.bank_name ?? '',
        bank_account_name: supplier.bank_account_name ?? '', bank_account_number: supplier.bank_account_number ?? '', notes: supplier.notes ?? '',
    });
    const [ask, confirmDialog] = useConfirm();

    const submit = (e) => {
        e.preventDefault();
        patch(route('suppliers.update', supplier.id), { preserveScroll: true });
    };

    const remove = async () => {
        if (await ask({ title: t('supplier.remove_confirm_title', { name: supplier.name }), body: t('supplier.remove_confirm_body'), confirmLabel: t('supplier.remove_confirm_button') })) {
            router.delete(route('suppliers.destroy', supplier.id));
        }
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">{supplier.name}</h2>}>
            <Page title={`${supplier.code} · ${supplier.name}`} back={<BackLink href={route('suppliers.index')}>{t('supplier.back_to_list')}</BackLink>}>
                <FlashMessages />
                <Card as="form" onSubmit={submit}>
                    <SectionHeader as="h3" title={t('supplier.details_title')} />
                    <fieldset disabled={!canManage} className="space-y-6">
                        <Field id="supplier_name" label={t('supplier.name')} error={errors.name}>
                            <Input value={data.name} onChange={(e) => setData('name', e.target.value)} maxLength={255} autoComplete="off" />
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
                    </fieldset>
                    {canManage && (
                        <div className="mt-6 flex flex-col gap-3 sm:flex-row">
                            <Button type="submit" fullWidth busy={processing}>{t('supplier.save_button')}</Button>
                            <Button type="button" variant="danger" fullWidth onClick={remove}>{t('supplier.remove_button')}</Button>
                        </div>
                    )}
                </Card>
            </Page>
            {confirmDialog}
        </AuthenticatedLayout>
    );
}
