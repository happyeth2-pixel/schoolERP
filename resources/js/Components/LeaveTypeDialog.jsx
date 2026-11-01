import Modal from '@/Components/Modal';
import { Button, Field, Input, TextArea } from '@/Components/ui';
import { useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

export default function LeaveTypeDialog({ open, leaveType, onClose }) {
    const { t } = useTranslation();
    const editing = Boolean(leaveType);
    const { data, setData, post, put, processing, errors, reset } = useForm({
        name: leaveType?.name ?? '',
        default_annual_days: leaveType?.default_annual_days != null ? String(leaveType.default_annual_days) : '',
        requires_document: leaveType?.requires_document ?? false,
        notes: leaveType?.notes ?? '',
    });

    const submit = (e) => {
        e.preventDefault();
        const onSuccess = () => { reset(); onClose(); };
        if (editing) {
            put(route('leave-types.update', leaveType.id), { preserveScroll: true, onSuccess });
        } else {
            post(route('leave-types.store'), { preserveScroll: true, onSuccess });
        }
    };

    return (
        <Modal show={open} onClose={onClose} maxWidth="lg">
            {open && (
                <form onSubmit={submit} className="space-y-6 p-6" data-testid="leave-type-form">
                    <h2 className="text-2xl font-bold text-stone-900 dark:text-stone-100">{editing ? t('leave.edit_type_title') : t('leave.add_type_title')}</h2>
                    <Field id="lt_name" label={t('leave.type_name')} error={errors.name}>
                        <Input value={data.name} onChange={(e) => setData('name', e.target.value)} maxLength={255} autoComplete="off" />
                    </Field>
                    <Field id="lt_default_days" label={t('leave.default_annual_days')} hint={t('leave.default_annual_days_hint')} error={errors.default_annual_days}>
                        <Input inputMode="decimal" value={data.default_annual_days} onChange={(e) => setData('default_annual_days', e.target.value)} />
                    </Field>
                    <label className="flex items-center gap-3 text-base text-stone-800 dark:text-stone-200">
                        <input type="checkbox" id="lt_requires_document" checked={data.requires_document} onChange={(e) => setData('requires_document', e.target.checked)} className="h-5 w-5 rounded border-stone-300" />
                        {t('leave.requires_document')}
                    </label>
                    <Field id="lt_notes" label={t('leave.notes')} error={errors.notes}>
                        <TextArea value={data.notes} onChange={(e) => setData('notes', e.target.value)} rows={2} maxLength={1000} />
                    </Field>
                    <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        <Button type="button" variant="secondary" onClick={onClose}>{t('common.cancel')}</Button>
                        <Button type="submit" busy={processing} disabled={!data.name}>{t('leave.save_type')}</Button>
                    </div>
                </form>
            )}
        </Modal>
    );
}
