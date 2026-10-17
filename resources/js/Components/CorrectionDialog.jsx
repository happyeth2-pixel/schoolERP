import Modal from '@/Components/Modal';
import { Button, Field, Input, Select, TextArea } from '@/Components/ui';
import { useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

export default function CorrectionDialog({ open, row, statuses, onClose }) {
    const { t } = useTranslation();
    const { data, setData, post, processing, errors, reset } = useForm({
        new_status: row?.status ?? 'present',
        new_check_in: row?.check_in ?? '',
        new_check_out: row?.check_out ?? '',
        new_overtime_minutes: row?.overtime_minutes ?? '',
        reason: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('staff-attendance.request-correction', row.attendance_id), {
            preserveScroll: true,
            onSuccess: () => { reset(); onClose(); },
        });
    };

    return (
        <Modal show={open} onClose={onClose} maxWidth="lg">
            {open && (
                <form onSubmit={submit} className="space-y-6 p-6" data-testid="correction-form">
                    <h2 className="text-2xl font-bold text-gray-900 dark:text-gray-100">{t('staff_attendance.correction_title', { name: row.full_name })}</h2>
                    <p className="text-base text-gray-600 dark:text-gray-400">{t('staff_attendance.correction_hint')}</p>

                    <Field id="correction_status" label={t('staff_attendance.status')} error={errors.new_status}>
                        <Select id="correction_status" value={data.new_status} onChange={(e) => setData('new_status', e.target.value)}>
                            {statuses.map((s) => <option key={s} value={s}>{t(`staff_attendance.status_${s}`)}</option>)}
                        </Select>
                    </Field>
                    <Field id="correction_check_in" label={t('staff_attendance.check_in')} error={errors.new_check_in}>
                        <Input type="time" value={data.new_check_in} onChange={(e) => setData('new_check_in', e.target.value)} />
                    </Field>
                    <Field id="correction_check_out" label={t('staff_attendance.check_out')} error={errors.new_check_out}>
                        <Input type="time" value={data.new_check_out} onChange={(e) => setData('new_check_out', e.target.value)} />
                    </Field>
                    <Field id="correction_overtime" label={t('staff_attendance.overtime_minutes')} error={errors.new_overtime_minutes}>
                        <Input type="number" min="0" max="1440" value={data.new_overtime_minutes} onChange={(e) => setData('new_overtime_minutes', e.target.value)} />
                    </Field>
                    <Field id="correction_reason" label={t('staff_attendance.correction_reason')} error={errors.reason}>
                        <TextArea value={data.reason} onChange={(e) => setData('reason', e.target.value)} rows={3} maxLength={1000} />
                    </Field>

                    <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        <Button type="button" variant="secondary" onClick={onClose}>{t('common.cancel')}</Button>
                        <Button type="submit" busy={processing} disabled={!data.reason}>{t('staff_attendance.correction_submit')}</Button>
                    </div>
                </form>
            )}
        </Modal>
    );
}
