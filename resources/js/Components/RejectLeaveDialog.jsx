import Modal from '@/Components/Modal';
import { Button, Field, TextArea } from '@/Components/ui';
import { useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

export default function RejectLeaveDialog({ open, request, onClose }) {
    const { t } = useTranslation();
    const { data, setData, post, processing, errors, reset } = useForm({ rejection_reason: '' });

    const submit = (e) => {
        e.preventDefault();
        post(route('leave-requests.reject', request.id), {
            preserveScroll: true,
            onSuccess: () => { reset(); onClose(); },
        });
    };

    return (
        <Modal show={open} onClose={onClose} maxWidth="lg">
            {open && (
                <form onSubmit={submit} className="space-y-6 p-6" data-testid="reject-leave-form">
                    <h2 className="text-2xl font-bold text-stone-900 dark:text-stone-100">{t('leave.reject_title', { name: request.employee_name })}</h2>
                    <Field id="reject_reason" label={t('leave.reject_reason_label')} error={errors.rejection_reason}>
                        <TextArea value={data.rejection_reason} onChange={(e) => setData('rejection_reason', e.target.value)} rows={3} maxLength={1000} autoFocus />
                    </Field>
                    <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        <Button type="button" variant="secondary" onClick={onClose}>{t('common.cancel')}</Button>
                        <Button type="submit" variant="danger" busy={processing} disabled={!data.rejection_reason}>{t('leave.reject')}</Button>
                    </div>
                </form>
            )}
        </Modal>
    );
}
