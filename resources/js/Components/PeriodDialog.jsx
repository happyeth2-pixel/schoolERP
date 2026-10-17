import Modal from '@/Components/Modal';
import { Button, Field, Input } from '@/Components/ui';
import { useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

export default function PeriodDialog({ open, period, onClose }) {
    const { t } = useTranslation();
    const editing = Boolean(period);
    const { data, setData, post, put, processing, errors, reset } = useForm({
        period_number: period?.period_number != null ? String(period.period_number) : '',
        name: period?.name ?? '',
        start_time: period?.start_time ?? '',
        end_time: period?.end_time ?? '',
    });

    const submit = (e) => {
        e.preventDefault();
        const onSuccess = () => { reset(); onClose(); };
        if (editing) {
            put(route('periods.update', period.id), { preserveScroll: true, onSuccess });
        } else {
            post(route('periods.store'), { preserveScroll: true, onSuccess });
        }
    };

    return (
        <Modal show={open} onClose={onClose} maxWidth="lg">
            {open && (
                <form onSubmit={submit} className="space-y-6 p-6" data-testid="period-form">
                    <h2 className="text-2xl font-bold text-gray-900 dark:text-gray-100">{editing ? t('timetable.edit_period_title') : t('timetable.add_period_title')}</h2>
                    <Field id="period_number" label={t('timetable.period_number_label')} error={errors.period_number}>
                        <Input inputMode="numeric" value={data.period_number} onChange={(e) => setData('period_number', e.target.value)} />
                    </Field>
                    <Field id="period_name" label={t('timetable.period_name_label')} hint={t('timetable.period_name_hint')} error={errors.name}>
                        <Input value={data.name} onChange={(e) => setData('name', e.target.value)} maxLength={255} />
                    </Field>
                    <Field id="period_start" label={t('timetable.period_start_label')} error={errors.start_time}>
                        <Input type="time" value={data.start_time} onChange={(e) => setData('start_time', e.target.value)} />
                    </Field>
                    <Field id="period_end" label={t('timetable.period_end_label')} error={errors.end_time}>
                        <Input type="time" value={data.end_time} onChange={(e) => setData('end_time', e.target.value)} />
                    </Field>
                    <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        <Button type="button" variant="secondary" onClick={onClose}>{t('common.cancel')}</Button>
                        <Button type="submit" busy={processing} disabled={!data.period_number || !data.start_time || !data.end_time}>{t('timetable.save_period_button')}</Button>
                    </div>
                </form>
            )}
        </Modal>
    );
}
