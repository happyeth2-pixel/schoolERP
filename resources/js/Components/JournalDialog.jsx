import Modal from '@/Components/Modal';
import { Button, Field, Input, Select } from '@/Components/ui';
import { useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

export default function JournalDialog({ open, types, onClose }) {
    const { t } = useTranslation();
    const { data, setData, post, processing, errors, reset } = useForm({ code: '', name: '', type: '' });

    const submit = (e) => {
        e.preventDefault();
        post(route('journals.store'), { preserveScroll: true, onSuccess: () => { reset(); onClose(); } });
    };

    return (
        <Modal show={open} onClose={onClose} maxWidth="lg">
            {open && (
                <form onSubmit={submit} className="space-y-6 p-6" data-testid="journal-form">
                    <h2 className="text-2xl font-bold text-gray-900 dark:text-gray-100">{t('journal.add_title')}</h2>
                    <Field id="journal_code" label={t('journal.code')} error={errors.code}>
                        <Input value={data.code} onChange={(e) => setData('code', e.target.value.toUpperCase())} maxLength={20} autoComplete="off" />
                    </Field>
                    <Field id="journal_name" label={t('journal.name')} error={errors.name}>
                        <Input value={data.name} onChange={(e) => setData('name', e.target.value)} maxLength={255} autoComplete="off" />
                    </Field>
                    <Field id="journal_type" label={t('journal.type')} error={errors.type}>
                        <Select value={data.type} onChange={(e) => setData('type', e.target.value)}>
                            <option value="">--</option>
                            {types.map((ty) => <option key={ty} value={ty}>{t(`journal.type_${ty}`)}</option>)}
                        </Select>
                    </Field>
                    <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        <Button type="button" variant="secondary" onClick={onClose}>{t('common.cancel')}</Button>
                        <Button type="submit" busy={processing} disabled={!data.code || !data.name || !data.type}>{t('journal.save_button')}</Button>
                    </div>
                </form>
            )}
        </Modal>
    );
}
