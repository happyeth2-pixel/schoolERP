import Modal from '@/Components/Modal';
import { Button, Field, Input, TextArea } from '@/Components/ui';
import { useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

export default function PerformanceCycleDialog({ open, cycle, onClose }) {
    const { t } = useTranslation();
    const editing = Boolean(cycle);
    const { data, setData, post, put, processing, errors, reset } = useForm({
        name: cycle?.name ?? '',
        start_date: cycle?.start_date ?? '',
        end_date: cycle?.end_date ?? '',
        notes: cycle?.notes ?? '',
    });

    const submit = (e) => {
        e.preventDefault();
        const onSuccess = () => { reset(); onClose(); };
        if (editing) {
            put(route('performance-cycles.update', cycle.id), { preserveScroll: true, onSuccess });
        } else {
            post(route('performance-cycles.store'), { preserveScroll: true, onSuccess });
        }
    };

    return (
        <Modal show={open} onClose={onClose} maxWidth="lg">
            {open && (
                <form onSubmit={submit} className="space-y-6 p-6" data-testid="performance-cycle-form">
                    <h2 className="text-2xl font-bold text-gray-900 dark:text-gray-100">{editing ? t('performance.cycles_title') : t('performance.add_cycle_button')}</h2>
                    <Field id="cycle_name" label={t('performance.cycle_name_label')} error={errors.name}>
                        <Input value={data.name} onChange={(e) => setData('name', e.target.value)} maxLength={255} autoComplete="off" />
                    </Field>
                    <Field id="cycle_start" label={t('performance.cycle_start_label')} error={errors.start_date}>
                        <Input type="date" value={data.start_date} onChange={(e) => setData('start_date', e.target.value)} />
                    </Field>
                    <Field id="cycle_end" label={t('performance.cycle_end_label')} error={errors.end_date}>
                        <Input type="date" value={data.end_date} onChange={(e) => setData('end_date', e.target.value)} />
                    </Field>
                    <Field id="cycle_notes" label={t('performance.cycle_notes_label')} error={errors.notes}>
                        <TextArea value={data.notes} onChange={(e) => setData('notes', e.target.value)} rows={2} maxLength={1000} />
                    </Field>
                    <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        <Button type="button" variant="secondary" onClick={onClose}>{t('common.cancel')}</Button>
                        <Button type="submit" busy={processing} disabled={!data.name || !data.start_date || !data.end_date}>{t('performance.save_cycle_button')}</Button>
                    </div>
                </form>
            )}
        </Modal>
    );
}
