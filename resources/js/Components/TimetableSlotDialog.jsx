import Modal from '@/Components/Modal';
import { Alert, Button, Field, FieldError, Select, TextArea } from '@/Components/ui';
import { useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

const CONFLICT_FIELDS = ['teacher_id', 'room_id', 'section_id'];
const DAYS = [1, 2, 3, 4, 5, 6, 7];

export default function TimetableSlotDialog({ open, slot, prefill, terms, sections, subjects, teachers, rooms, periods, defaultTermId, canOverride, onClose }) {
    const { t } = useTranslation();
    const editing = Boolean(slot);
    const base = slot ?? prefill ?? {};
    const { data, setData, post, put, processing, errors, reset, clearErrors } = useForm({
        term_id: base.term_id ?? defaultTermId ?? (terms[0]?.id ?? ''),
        section_id: base.section_id ?? '',
        subject_id: base.subject_id ?? '',
        teacher_id: base.teacher_id ?? '',
        room_id: base.room_id ?? '',
        period_id: base.period_id ?? (periods[0]?.id ?? ''),
        day_of_week: base.day_of_week ?? 1,
        override_confirmed: false,
        override_reason: '',
    });

    const hasConflict = CONFLICT_FIELDS.some((f) => errors[f]);

    const submit = (overriding) => (e) => {
        e?.preventDefault();
        setData('override_confirmed', overriding);
        const options = {
            preserveScroll: true,
            onSuccess: () => { reset(); onClose(); },
        };
        if (editing) {
            put(route('timetable.update', slot.id), options);
        } else {
            post(route('timetable.store'), options);
        }
    };

    return (
        <Modal show={open} onClose={onClose} maxWidth="lg">
            {open && (
                <form className="space-y-6 p-6" data-testid="timetable-slot-form">
                    <h2 className="text-2xl font-bold text-gray-900 dark:text-gray-100">{editing ? t('timetable.edit_slot_title') : t('timetable.add_slot_title')}</h2>

                    <Field id="slot_term" label={t('timetable.term_label')} error={errors.term_id}>
                        <Select id="slot_term" value={data.term_id} onChange={(e) => { setData('term_id', e.target.value); clearErrors(); }}>
                            {terms.map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}
                        </Select>
                    </Field>
                    <Field id="slot_section" label={t('timetable.section_label')} error={errors.section_id}>
                        <Select id="slot_section" value={data.section_id} onChange={(e) => { setData('section_id', e.target.value); clearErrors(); }}>
                            <option value="" disabled>{t('timetable.pick_option')}</option>
                            {sections.map((x) => <option key={x.id} value={x.id}>{x.label}</option>)}
                        </Select>
                    </Field>
                    <Field id="slot_subject" label={t('timetable.subject_label')} error={errors.subject_id}>
                        <Select id="slot_subject" value={data.subject_id} onChange={(e) => { setData('subject_id', e.target.value); clearErrors(); }}>
                            <option value="" disabled>{t('timetable.pick_option')}</option>
                            {subjects.map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}
                        </Select>
                    </Field>
                    <Field id="slot_teacher" label={t('timetable.teacher_label')} error={errors.teacher_id}>
                        <Select id="slot_teacher" value={data.teacher_id} onChange={(e) => { setData('teacher_id', e.target.value); clearErrors(); }}>
                            <option value="" disabled>{t('timetable.pick_option')}</option>
                            {teachers.map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}
                        </Select>
                    </Field>
                    <Field id="slot_room" label={t('timetable.room_label')} optional error={errors.room_id}>
                        <Select id="slot_room" value={data.room_id} onChange={(e) => { setData('room_id', e.target.value); clearErrors(); }}>
                            <option value="">{t('timetable.no_room')}</option>
                            {rooms.map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}
                        </Select>
                    </Field>
                    <Field id="slot_day" label={t('timetable.day_label')} error={errors.day_of_week}>
                        <Select id="slot_day" value={data.day_of_week} onChange={(e) => { setData('day_of_week', Number(e.target.value)); clearErrors(); }}>
                            {DAYS.map((d) => <option key={d} value={d}>{t(`timetable.day_${d}`)}</option>)}
                        </Select>
                    </Field>
                    <Field id="slot_period" label={t('timetable.period_label')} error={errors.period_id}>
                        <Select id="slot_period" value={data.period_id} onChange={(e) => { setData('period_id', e.target.value); clearErrors(); }}>
                            {periods.map((x) => <option key={x.id} value={x.id}>{x.label}</option>)}
                        </Select>
                    </Field>

                    {hasConflict && canOverride && (
                        <Alert tone="warning" title={t('timetable.conflict_title')}>
                            <p className="mb-3">{t('timetable.conflict_body')}</p>
                            <Field id="slot_override_reason" label={t('timetable.override_reason_label')} error={errors.override_reason}>
                                <TextArea value={data.override_reason} onChange={(e) => setData('override_reason', e.target.value)} rows={2} maxLength={1000} />
                            </Field>
                            <Button type="button" variant="danger" className="mt-3" busy={processing} disabled={!data.override_reason} onClick={submit(true)}>
                                {t('timetable.override_button')}
                            </Button>
                        </Alert>
                    )}
                    {hasConflict && !canOverride && <FieldError message={t('timetable.conflict_no_permission')} />}

                    <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        <Button type="button" variant="secondary" onClick={onClose}>{t('common.cancel')}</Button>
                        <Button type="button" busy={processing} onClick={submit(false)} disabled={!data.section_id || !data.subject_id || !data.teacher_id}>
                            {t('timetable.save_slot_button')}
                        </Button>
                    </div>
                </form>
            )}
        </Modal>
    );
}
