import Modal from '@/Components/Modal';
import DateInput from '@/Components/DateInput';
import { Button, Field, Input, Select, TextArea } from '@/Components/ui';
import { useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

export default function LeaveRequestDialog({ open, employeeId, leaveTypes, request, onClose }) {
    const { t } = useTranslation();
    const editing = Boolean(request);
    const { data, setData, post, transform, processing, errors, reset } = useForm({
        leave_type_id: request?.leave_type_id ?? (leaveTypes[0]?.id ?? ''),
        start_date: request?.start_date ?? '',
        end_date: request?.end_date ?? '',
        reason: request?.reason ?? '',
        document: null,
        action: 'submit',
    });

    const submit = (actionValue) => (e) => {
        e.preventDefault();
        setData('action', actionValue);
        const onSuccess = () => { reset(); onClose(); };
        const options = { preserveScroll: true, forceFormData: true, onSuccess };
        if (editing) {
            // PHP never parses a multipart/form-data body on a real PUT request, so -- per Inertia's own
            // documented workaround -- a PUT that might carry a file must travel as a spoofed POST instead.
            transform((d) => ({ ...d, _method: 'put' }));
            post(route('leave-requests.update', request.id), options);
        } else {
            post(route('leave-requests.store', employeeId), options);
        }
    };

    const days = data.start_date && data.end_date
        ? Math.max(0, Math.round((new Date(data.end_date) - new Date(data.start_date)) / 86400000) + 1)
        : null;

    const selectedType = leaveTypes.find((lt) => String(lt.id) === String(data.leave_type_id));

    return (
        <Modal show={open} onClose={onClose} maxWidth="lg">
            {open && (
                <form className="space-y-6 p-6" data-testid="leave-request-form">
                    <h2 className="text-2xl font-bold text-stone-900 dark:text-stone-100">{editing ? t('leave.edit_title') : t('leave.request_title')}</h2>

                    <Field id="leave_type" label={t('leave.leave_type')} error={errors.leave_type_id}>
                        <Select id="leave_type" value={data.leave_type_id} onChange={(e) => setData('leave_type_id', e.target.value)}>
                            {leaveTypes.map((lt) => <option key={lt.id} value={lt.id}>{lt.name}</option>)}
                        </Select>
                    </Field>
                    <Field id="leave_start" label={t('leave.start_date')} error={errors.start_date}>
                        <DateInput value={data.start_date} onChange={(v) => setData('start_date', v)} />
                    </Field>
                    <Field id="leave_end" label={t('leave.end_date')} error={errors.end_date}>
                        <DateInput value={data.end_date} onChange={(v) => setData('end_date', v)} />
                    </Field>
                    {days !== null && <p className="text-base text-stone-700 dark:text-stone-300" data-testid="leave-day-count">{t('leave.days_x', { count: days })}</p>}
                    <Field id="leave_reason" label={t('leave.reason')} error={errors.reason}>
                        <TextArea value={data.reason} onChange={(e) => setData('reason', e.target.value)} rows={3} maxLength={1000} />
                    </Field>
                    <Field
                        id="leave_document"
                        label={selectedType?.requires_document ? t('leave.document_required') : t('leave.document_optional')}
                        error={errors.document}
                    >
                        <input id="leave_document" type="file" accept="application/pdf" onChange={(e) => setData('document', e.target.files[0] ?? null)} className="block w-full text-base" />
                    </Field>

                    <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        <Button type="button" variant="secondary" onClick={onClose}>{t('common.cancel')}</Button>
                        <Button type="button" variant="secondary" busy={processing} onClick={submit('draft')} disabled={!data.leave_type_id || !data.start_date || !data.end_date}>{t('leave.save_draft')}</Button>
                        <Button type="button" busy={processing} onClick={submit('submit')} disabled={!data.leave_type_id || !data.start_date || !data.end_date || !data.reason}>{t('leave.submit_button')}</Button>
                    </div>
                </form>
            )}
        </Modal>
    );
}
