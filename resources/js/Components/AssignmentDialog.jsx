import DateInput from '@/Components/DateInput';
import Modal from '@/Components/Modal';
import { Button, Field, Select, TextArea } from '@/Components/ui';
import { useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

// Editing (not just creating) can attach a NEW file, so the edit path must POST with a spoofed _method
// rather than call put() directly -- PHP never parses a multipart body on a literal HTTP PUT. See the
// identical fix already proven by DisciplineIncidentDialog.
export default function AssignmentDialog({ open, teachingAssignments, assignment, onClose }) {
    const { t } = useTranslation();
    const editing = Boolean(assignment);
    const { data, setData, post, processing, errors, reset, transform } = useForm({
        teaching_assignment_id: teachingAssignments[0]?.id ?? '',
        title: assignment?.title ?? '',
        description: assignment?.description ?? '',
        due_date: assignment?.due_date ?? new Date().toISOString().slice(0, 10),
        status: assignment?.status ?? 'draft',
        attachment: null,
    });

    const submit = (e) => {
        e.preventDefault();
        const onSuccess = () => { reset(); onClose(); };
        const chosen = teachingAssignments.find((ta) => String(ta.id) === String(data.teaching_assignment_id));

        if (editing) {
            transform((d) => ({ ...d, _method: 'put' }));
            post(route('assignments.update', assignment.id), { preserveScroll: true, forceFormData: true, onSuccess });
        } else {
            transform((d) => ({ ...d, section_id: chosen?.section_id, subject_id: chosen?.subject_id }));
            post(route('assignments.store'), { preserveScroll: true, forceFormData: true, onSuccess });
        }
    };

    return (
        <Modal show={open} onClose={onClose} maxWidth="lg">
            {open && (
                <form onSubmit={submit} className="space-y-6 p-6" data-testid="assignment-form">
                    <h2 className="text-2xl font-bold text-stone-900 dark:text-stone-100">{editing ? t('homework.edit_title') : t('homework.add_title')}</h2>
                    {!editing && (
                        <Field id="asg_class" label={t('homework.class_subject_label')} error={errors.section_id ?? errors.subject_id}>
                            <Select id="asg_class" value={data.teaching_assignment_id} onChange={(e) => setData('teaching_assignment_id', e.target.value)}>
                                {teachingAssignments.map((ta) => <option key={ta.id} value={ta.id}>{ta.label}</option>)}
                            </Select>
                        </Field>
                    )}
                    <Field id="asg_title" label={t('homework.title_label')} error={errors.title}>
                        <input id="asg_title" type="text" value={data.title} maxLength={200} onChange={(e) => setData('title', e.target.value)} className="block w-full rounded-xl border-2 border-stone-300 px-4 py-3 text-base dark:border-stone-600 dark:bg-stone-800 dark:text-stone-100" />
                    </Field>
                    <Field id="asg_due_date" label={t('homework.due_date_label')} error={errors.due_date}>
                        <DateInput id="asg_due_date" large value={data.due_date} onChange={(v) => setData('due_date', v)} />
                    </Field>
                    <Field id="asg_description" label={t('homework.description_label')} optional error={errors.description}>
                        <TextArea value={data.description} onChange={(e) => setData('description', e.target.value)} rows={4} maxLength={5000} />
                    </Field>
                    <Field id="asg_status" label={t('homework.status_label')} hint={t('homework.status_hint')} error={errors.status}>
                        <Select id="asg_status" value={data.status} onChange={(e) => setData('status', e.target.value)}>
                            <option value="draft">{t('status.draft')}</option>
                            <option value="published">{t('status.published')}</option>
                        </Select>
                    </Field>
                    <Field id="asg_attachment" label={t('homework.attachment_label')} optional hint={t('homework.attachment_hint')} error={errors.attachment}>
                        <input id="asg_attachment" type="file" accept="image/jpeg,image/png,application/pdf" onChange={(e) => setData('attachment', e.target.files[0] ?? null)} className="block w-full text-base" />
                    </Field>
                    <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        <Button type="button" variant="secondary" onClick={onClose}>{t('common.cancel')}</Button>
                        <Button type="submit" busy={processing} disabled={!data.title || !data.due_date}>{t('homework.save_button')}</Button>
                    </div>
                </form>
            )}
        </Modal>
    );
}
