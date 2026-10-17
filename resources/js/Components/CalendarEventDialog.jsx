import Modal from '@/Components/Modal';
import { Button, Field, Input, Select, TextArea } from '@/Components/ui';
import { useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

export default function CalendarEventDialog({ open, event, eventTypes, audiences, branches, canPostSchoolWide, onClose }) {
    const { t } = useTranslation();
    const editing = Boolean(event);
    const { data, setData, post, put, processing, errors, reset } = useForm({
        title: event?.title ?? '',
        description: event?.description ?? '',
        event_type: event?.event_type ?? Object.keys(eventTypes)[0],
        audience: event?.audience ?? 'everyone',
        branch_id: event?.branch_id ?? (canPostSchoolWide ? '' : (branches[0]?.id ?? '')),
        start_date: event?.start_date ?? '',
        end_date: event?.end_date ?? '',
    });

    const submit = (e) => {
        e.preventDefault();
        const onSuccess = () => { reset(); onClose(); };
        if (editing) {
            put(route('calendar.update', event.id), { preserveScroll: true, onSuccess });
        } else {
            post(route('calendar.store'), { preserveScroll: true, onSuccess });
        }
    };

    return (
        <Modal show={open} onClose={onClose} maxWidth="lg">
            {open && (
                <form onSubmit={submit} className="space-y-6 p-6" data-testid="calendar-event-form">
                    <h2 className="text-2xl font-bold text-gray-900 dark:text-gray-100">{editing ? t('calendar.edit_title') : t('calendar.add_title')}</h2>
                    <Field id="cal_title" label={t('calendar.title_label')} error={errors.title}>
                        <Input value={data.title} onChange={(e) => setData('title', e.target.value)} maxLength={150} autoComplete="off" />
                    </Field>
                    <Field id="cal_type" label={t('calendar.type_label')} error={errors.event_type}>
                        <Select id="cal_type" value={data.event_type} onChange={(e) => setData('event_type', e.target.value)}>
                            {Object.entries(eventTypes).map(([v, label]) => <option key={v} value={v}>{t(`calendar.type_${v}`, label)}</option>)}
                        </Select>
                    </Field>
                    <Field id="cal_start" label={t('calendar.start_label')} error={errors.start_date}>
                        <Input type="date" value={data.start_date} onChange={(e) => setData('start_date', e.target.value)} />
                    </Field>
                    <Field id="cal_end" label={t('calendar.end_label')} optional hint={t('calendar.end_hint')} error={errors.end_date}>
                        <Input type="date" value={data.end_date} onChange={(e) => setData('end_date', e.target.value)} />
                    </Field>
                    <Field id="cal_audience" label={t('calendar.audience_label')} error={errors.audience}>
                        <Select id="cal_audience" value={data.audience} onChange={(e) => setData('audience', e.target.value)}>
                            {Object.entries(audiences).map(([v, label]) => <option key={v} value={v}>{t(`calendar.aud_${v}`, label)}</option>)}
                        </Select>
                    </Field>
                    {canPostSchoolWide && (
                        <Field id="cal_branch" label={t('calendar.branch_label')} optional hint={t('calendar.branch_hint')} error={errors.branch_id}>
                            <Select id="cal_branch" value={data.branch_id} onChange={(e) => setData('branch_id', e.target.value)}>
                                <option value="">{t('calendar.all_branches')}</option>
                                {branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
                            </Select>
                        </Field>
                    )}
                    <Field id="cal_desc" label={t('calendar.description_label')} optional error={errors.description}>
                        <TextArea value={data.description} onChange={(e) => setData('description', e.target.value)} rows={3} maxLength={5000} />
                    </Field>
                    <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        <Button type="button" variant="secondary" onClick={onClose}>{t('common.cancel')}</Button>
                        <Button type="submit" busy={processing} disabled={!data.title || !data.start_date}>{t('calendar.save_button')}</Button>
                    </div>
                </form>
            )}
        </Modal>
    );
}
