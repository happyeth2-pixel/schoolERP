import DateInput from '@/Components/DateInput';
import Modal from '@/Components/Modal';
import { Button, Field, Select, TextArea } from '@/Components/ui';
import { useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

// Editing (not just creating) can attach a NEW file, so the edit path must POST with a spoofed _method
// rather than call put() directly -- PHP never parses a multipart body on a literal HTTP PUT. See the
// identical, still-unfixed latent bug this project's own memory already flags on ContractDialog.
export default function DisciplineIncidentDialog({ open, studentId, incident, categories, actions, statuses, onClose }) {
    const { t } = useTranslation();
    const editing = Boolean(incident);
    const { data, setData, post, processing, errors, reset, transform } = useForm({
        category: incident?.category ?? categories[0],
        description: incident?.description ?? '',
        incident_date: incident?.incident_date ?? new Date().toISOString().slice(0, 10),
        action_taken: incident?.action_taken ?? 'none',
        status: incident?.status ?? 'open',
        attachment: null,
    });

    const submit = (e) => {
        e.preventDefault();
        const onSuccess = () => { reset(); onClose(); };
        if (editing) {
            transform((d) => ({ ...d, _method: 'put' }));
            post(route('discipline.update', incident.id), { preserveScroll: true, forceFormData: true, onSuccess });
        } else {
            post(route('discipline.store', studentId), { preserveScroll: true, forceFormData: true, onSuccess });
        }
    };

    return (
        <Modal show={open} onClose={onClose} maxWidth="lg">
            {open && (
                <form onSubmit={submit} className="space-y-6 p-6" data-testid="discipline-incident-form">
                    <h2 className="text-2xl font-bold text-stone-900 dark:text-stone-100">{editing ? t('discipline.edit_title') : t('discipline.add_title')}</h2>
                    <Field id="inc_category" label={t('discipline.category_label')} error={errors.category}>
                        <Select id="inc_category" value={data.category} onChange={(e) => setData('category', e.target.value)}>
                            {categories.map((c) => <option key={c} value={c}>{t(`discipline.category_${c}`)}</option>)}
                        </Select>
                    </Field>
                    <Field id="inc_date" label={t('discipline.date_label')} error={errors.incident_date}>
                        <DateInput id="inc_date" large value={data.incident_date} max={new Date().toISOString().slice(0, 10)} onChange={(v) => setData('incident_date', v)} />
                    </Field>
                    <Field id="inc_description" label={t('discipline.description_label')} error={errors.description}>
                        <TextArea value={data.description} onChange={(e) => setData('description', e.target.value)} rows={3} maxLength={2000} />
                    </Field>
                    <Field id="inc_action" label={t('discipline.action_label')} error={errors.action_taken}>
                        <Select id="inc_action" value={data.action_taken} onChange={(e) => setData('action_taken', e.target.value)}>
                            {actions.map((a) => <option key={a} value={a}>{t(`discipline.action_${a}`)}</option>)}
                        </Select>
                    </Field>
                    <Field id="inc_status" label={t('discipline.status_label')} error={errors.status}>
                        <Select id="inc_status" value={data.status} onChange={(e) => setData('status', e.target.value)}>
                            {statuses.map((s) => <option key={s} value={s}>{t(`discipline.status_${s}`)}</option>)}
                        </Select>
                    </Field>
                    <Field id="inc_attachment" label={t('discipline.attachment_label')} optional hint={t('discipline.attachment_hint')} error={errors.attachment}>
                        <input id="inc_attachment" type="file" accept="image/jpeg,image/png,application/pdf" onChange={(e) => setData('attachment', e.target.files[0] ?? null)} className="block w-full text-base" />
                    </Field>
                    <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        <Button type="button" variant="secondary" onClick={onClose}>{t('common.cancel')}</Button>
                        <Button type="submit" busy={processing} disabled={!data.description}>{t('discipline.save_button')}</Button>
                    </div>
                </form>
            )}
        </Modal>
    );
}
