import { Alert, BackLink, Button, Card, Field, FlashMessages, Input, Page, TextArea } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

// A student's medical profile -- its own page, never folded into the ordinary profile/list, since
// medical-records-view/manage are separate permissions from manage-students (reaching this page at all
// already proves the viewer holds one of them; see StudentMedicalRecordController).

export default function Medical({ student, record, canManage }) {
    const { t } = useTranslation();
    const { data, setData, put, processing, errors, isDirty } = useForm({
        blood_type: record.blood_type ?? '',
        allergies: record.allergies ?? '',
        conditions: record.conditions ?? '',
        medications: record.medications ?? '',
        physician_name: record.physician_name ?? '',
        physician_phone: record.physician_phone ?? '',
        notes: record.notes ?? '',
    });
    const set = (k) => (e) => setData(k, e.target.value);

    const submit = (e) => {
        e.preventDefault();
        put(route('students.medical.update', student.id), { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout header={<h2 className="truncate text-lg font-semibold text-gray-800 dark:text-gray-200">{t('medical.page_title')}</h2>}>
            <Page
                title={t('medical.title_for', { name: student.name })}
                description={t('medical.page_desc')}
                width="narrow"
                back={<BackLink href={route('students.edit', student.id)}>{t('medical.back_to_profile')}</BackLink>}
            >
                <FlashMessages />
                <Alert tone="info">{t('medical.confidential_note')}</Alert>
                {!canManage && <Alert tone="neutral">{t('medical.read_only')}</Alert>}

                <form onSubmit={submit}>
                    <Card>
                        <fieldset disabled={!canManage} className="space-y-5" data-testid="medical-form">
                            <Field id="med_blood_type" label={t('medical.blood_type_label')} optional error={errors.blood_type}>
                                <Input value={data.blood_type} onChange={set('blood_type')} maxLength={10} className="max-w-[8rem]" />
                            </Field>
                            <Field id="med_allergies" label={t('medical.allergies_label')} optional hint={t('medical.allergies_hint')} error={errors.allergies}>
                                <TextArea value={data.allergies} onChange={set('allergies')} rows={2} maxLength={2000} />
                            </Field>
                            <Field id="med_conditions" label={t('medical.conditions_label')} optional hint={t('medical.conditions_hint')} error={errors.conditions}>
                                <TextArea value={data.conditions} onChange={set('conditions')} rows={2} maxLength={2000} />
                            </Field>
                            <Field id="med_medications" label={t('medical.medications_label')} optional error={errors.medications}>
                                <TextArea value={data.medications} onChange={set('medications')} rows={2} maxLength={2000} />
                            </Field>
                            <Field id="med_physician_name" label={t('medical.physician_name_label')} optional error={errors.physician_name}>
                                <Input value={data.physician_name} onChange={set('physician_name')} maxLength={150} />
                            </Field>
                            <Field id="med_physician_phone" label={t('medical.physician_phone_label')} optional error={errors.physician_phone}>
                                <Input type="tel" inputMode="tel" value={data.physician_phone} onChange={set('physician_phone')} maxLength={30} />
                            </Field>
                            <Field id="med_notes" label={t('medical.notes_label')} optional error={errors.notes}>
                                <TextArea value={data.notes} onChange={set('notes')} rows={3} maxLength={2000} />
                            </Field>
                        </fieldset>

                        {record.updated_by && (
                            <p className="mt-4 text-sm text-gray-600 dark:text-gray-400" data-testid="medical-last-updated">
                                {t('medical.last_updated', { name: record.updated_by, when: record.updated_at })}
                            </p>
                        )}

                        {canManage && (
                            <div className="mt-5 flex justify-end">
                                <Button type="submit" busy={processing} disabled={!isDirty}>{t('medical.save_button')}</Button>
                            </div>
                        )}
                    </Card>
                </form>
            </Page>
        </AuthenticatedLayout>
    );
}
