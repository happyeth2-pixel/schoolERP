import Modal from '@/Components/Modal';
import { Button, Field, Input, Select } from '@/Components/ui';
import { useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

export default function AccountDialog({ open, account, accounts, types, onClose }) {
    const { t } = useTranslation();
    const editing = account !== null;
    const { data, setData, post, patch, processing, errors, reset } = useForm({
        code: account?.code ?? '',
        name: account?.name ?? '',
        type: account?.type ?? '',
        parent_id: account?.parent_id ? String(account.parent_id) : '',
        is_header: account?.is_header ?? false,
    });

    const headers = accounts.filter((a) => a.is_header && a.id !== account?.id);

    const submit = (e) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => { reset(); onClose(); } };
        if (editing) {
            patch(route('accounts.update', account.id), options);
        } else {
            post(route('accounts.store'), options);
        }
    };

    return (
        <Modal show={open} onClose={onClose} maxWidth="lg">
            {open && (
                <form onSubmit={submit} className="space-y-6 p-6" data-testid="account-form">
                    <h2 className="text-2xl font-bold text-gray-900 dark:text-gray-100">{editing ? t('account.edit_title') : t('account.add_title')}</h2>
                    {!editing && (
                        <Field id="account_code" label={t('account.code')} error={errors.code}>
                            <Input value={data.code} onChange={(e) => setData('code', e.target.value)} maxLength={20} autoComplete="off" />
                        </Field>
                    )}
                    <Field id="account_name" label={t('account.name')} error={errors.name}>
                        <Input value={data.name} onChange={(e) => setData('name', e.target.value)} maxLength={255} autoComplete="off" />
                    </Field>
                    {!editing && (
                        <Field id="account_type" label={t('account.type')} error={errors.type}>
                            <Select value={data.type} onChange={(e) => setData('type', e.target.value)}>
                                <option value="">--</option>
                                {types.map((ty) => <option key={ty} value={ty}>{t(`account.type_${ty}`)}</option>)}
                            </Select>
                        </Field>
                    )}
                    <Field id="account_parent" label={t('account.parent')} optional error={errors.parent_id}>
                        <Select value={data.parent_id} onChange={(e) => setData('parent_id', e.target.value)}>
                            <option value="">{t('account.no_parent')}</option>
                            {headers.map((h) => <option key={h.id} value={h.id}>{h.code} {h.name}</option>)}
                        </Select>
                    </Field>
                    {!editing && (
                        <label className="flex items-center gap-2 text-base text-gray-800 dark:text-gray-200">
                            <input type="checkbox" checked={data.is_header} onChange={(e) => setData('is_header', e.target.checked)} className="h-5 w-5 rounded border-gray-300" />
                            {t('account.is_header_label')}
                        </label>
                    )}
                    <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        <Button type="button" variant="secondary" onClick={onClose}>{t('common.cancel')}</Button>
                        <Button type="submit" busy={processing} disabled={!data.name || (!editing && (!data.code || !data.type))}>{t('account.save_button')}</Button>
                    </div>
                </form>
            )}
        </Modal>
    );
}
