import { Alert, BackLink, Card, Field, Input, Page, ReviewList, Select, TextArea, WizardShell, useWizard } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

export default function Create({ departments, positions, branches, linkableUsers, employmentStatuses, employmentTypes }) {
    const { t } = useTranslation();
    const form = useForm({
        full_name: '', phone: '', email: '', gender: '', date_of_birth: '', photo: null,
        hire_date: '', department_id: '', position_id: '', branch_id: branches.length === 1 ? String(branches[0].id) : '',
        employment_status: 'active', employment_type: 'full_time', qualifications: '',
        address: '', emergency_contact_name: '', emergency_contact_phone: '', notes: '', user_id: '',
    });
    const { data, setData, errors, processing, clearErrors } = form;
    const set = (k) => (v) => { clearErrors(k); setData(k, v); };

    const positionsForDepartment = data.department_id ? positions.filter((p) => String(p.department_id) === data.department_id) : positions;

    const steps = [
        { key: 'personal', label: t('employee.step_personal'), description: t('employee.step_personal_desc'), fields: ['full_name', 'phone', 'email', 'gender', 'date_of_birth'], isValid: () => data.full_name.trim() !== '' && data.phone.trim() !== '', whyBlocked: t('employee.why_personal') },
        { key: 'employment', label: t('employee.step_employment'), description: t('employee.step_employment_desc'), fields: ['hire_date', 'department_id', 'position_id', 'branch_id', 'employment_status', 'employment_type'], isValid: () => data.hire_date !== '' && (branches.length <= 1 || data.branch_id !== ''), whyBlocked: t('employee.why_employment') },
        { key: 'other', label: t('employee.step_other'), description: t('employee.step_other_desc') },
        { key: 'review', label: t('employee.step_review'), description: t('employee.step_review_desc') },
    ];

    const wizard = useWizard({
        steps, errors,
        onSubmit: () => form.post(route('employees.store'), { forceFormData: true }),
    });
    const key = wizard.step.key;
    const stepOf = (k) => steps.findIndex((s) => s.key === k);

    const department = departments.find((d) => String(d.id) === data.department_id);
    const position = positions.find((p) => String(p.id) === data.position_id);
    const branch = branches.find((b) => String(b.id) === data.branch_id);
    const linkedUser = linkableUsers.find((u) => String(u.id) === data.user_id);

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">{t('employee.new_title')}</h2>}>
            <Page title={t('employee.new_title')} back={<BackLink href={route('employees.index')}>{t('employee.back_to_list')}</BackLink>}>
                <Card>
                    <WizardShell wizard={wizard} finalLabel={t('employee.save_button')} processing={processing}>
                        {key === 'personal' && (
                            <div className="space-y-5">
                                <Field id="emp_full_name" label={t('employee.full_name')} error={errors.full_name}>
                                    <Input value={data.full_name} onChange={(e) => set('full_name')(e.target.value)} autoFocus maxLength={255} />
                                </Field>
                                <Field id="emp_phone" label={t('employee.phone')} error={errors.phone}>
                                    <Input value={data.phone} onChange={(e) => set('phone')(e.target.value)} maxLength={30} />
                                </Field>
                                <Field id="emp_email" label={t('employee.email')} hint={t('employee.email_hint')} error={errors.email}>
                                    <Input type="email" value={data.email} onChange={(e) => set('email')(e.target.value)} maxLength={255} />
                                </Field>
                                <Field id="emp_gender" label={t('employee.gender')} error={errors.gender}>
                                    <Select id="emp_gender" value={data.gender} onChange={(e) => set('gender')(e.target.value)}>
                                        <option value="">{t('employee.gender_unspecified')}</option>
                                        <option value="male">{t('employee.gender_male')}</option>
                                        <option value="female">{t('employee.gender_female')}</option>
                                    </Select>
                                </Field>
                                <Field id="emp_dob" label={t('employee.date_of_birth')} error={errors.date_of_birth}>
                                    <Input type="date" value={data.date_of_birth} onChange={(e) => set('date_of_birth')(e.target.value)} />
                                </Field>
                                <Field id="emp_photo" label={t('employee.photo')} hint={t('employee.photo_hint')} error={errors.photo}>
                                    <input id="emp_photo" type="file" accept="image/png,image/jpeg" onChange={(e) => set('photo')(e.target.files[0] ?? null)} className="block w-full text-base" />
                                </Field>
                            </div>
                        )}

                        {key === 'employment' && (
                            <div className="space-y-5">
                                <Field id="emp_hire_date" label={t('employee.hire_date')} error={errors.hire_date}>
                                    <Input type="date" value={data.hire_date} onChange={(e) => set('hire_date')(e.target.value)} />
                                </Field>
                                {branches.length > 1 && (
                                    <Field id="emp_branch" label={t('employee.branch')} error={errors.branch_id}>
                                        <Select id="emp_branch" value={data.branch_id} onChange={(e) => set('branch_id')(e.target.value)}>
                                            <option value="">{t('employee.select_branch')}</option>
                                            {branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
                                        </Select>
                                    </Field>
                                )}
                                <Field id="emp_department" label={t('employee.department')} error={errors.department_id}>
                                    <Select id="emp_department" value={data.department_id} onChange={(e) => { set('department_id')(e.target.value); set('position_id')(''); }}>
                                        <option value="">{t('employee.no_department')}</option>
                                        {departments.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
                                    </Select>
                                </Field>
                                <Field id="emp_position" label={t('employee.job_title')} hint={t('employee.job_title_hint')} error={errors.position_id}>
                                    <Select id="emp_position" value={data.position_id} onChange={(e) => set('position_id')(e.target.value)}>
                                        <option value="">{t('employee.no_job_title')}</option>
                                        {positionsForDepartment.map((p) => <option key={p.id} value={p.id}>{p.title}</option>)}
                                    </Select>
                                </Field>
                                <Field id="emp_status" label={t('employee.employment_status')} error={errors.employment_status}>
                                    <Select id="emp_status" value={data.employment_status} onChange={(e) => set('employment_status')(e.target.value)}>
                                        {employmentStatuses.map((s) => <option key={s} value={s}>{t(`employee.status_${s}`)}</option>)}
                                    </Select>
                                </Field>
                                <Field id="emp_type" label={t('employee.employment_type')} error={errors.employment_type}>
                                    <Select id="emp_type" value={data.employment_type} onChange={(e) => set('employment_type')(e.target.value)}>
                                        {employmentTypes.map((s) => <option key={s} value={s}>{t(`employee.type_${s}`)}</option>)}
                                    </Select>
                                </Field>
                                <Field id="emp_qualifications" label={t('employee.qualifications')} error={errors.qualifications}>
                                    <TextArea value={data.qualifications} onChange={(e) => set('qualifications')(e.target.value)} rows={3} maxLength={2000} />
                                </Field>
                            </div>
                        )}

                        {key === 'other' && (
                            <div className="space-y-5">
                                <Field id="emp_account" label={t('employee.link_account')} hint={t('employee.link_account_hint')} error={errors.user_id}>
                                    <Select id="emp_account" value={data.user_id} onChange={(e) => set('user_id')(e.target.value)}>
                                        <option value="">{t('employee.no_account')}</option>
                                        {linkableUsers.map((u) => <option key={u.id} value={u.id}>{u.name}{u.phone ? ` (${u.phone})` : ''}</option>)}
                                    </Select>
                                </Field>
                                <Field id="emp_address" label={t('employee.address')} error={errors.address}>
                                    <TextArea value={data.address} onChange={(e) => set('address')(e.target.value)} rows={2} maxLength={1000} />
                                </Field>
                                <Field id="emp_ec_name" label={t('employee.emergency_contact_name')} error={errors.emergency_contact_name}>
                                    <Input value={data.emergency_contact_name} onChange={(e) => set('emergency_contact_name')(e.target.value)} maxLength={255} />
                                </Field>
                                <Field id="emp_ec_phone" label={t('employee.emergency_contact_phone')} error={errors.emergency_contact_phone}>
                                    <Input value={data.emergency_contact_phone} onChange={(e) => set('emergency_contact_phone')(e.target.value)} maxLength={30} />
                                </Field>
                                <Field id="emp_notes" label={t('employee.notes')} hint={t('employee.notes_hint')} error={errors.notes}>
                                    <TextArea value={data.notes} onChange={(e) => set('notes')(e.target.value)} rows={3} maxLength={2000} />
                                </Field>
                            </div>
                        )}

                        {key === 'review' && (
                            <div className="space-y-5">
                                <div className="rounded-2xl border-2 border-indigo-300 bg-indigo-50 p-5 dark:border-indigo-700 dark:bg-indigo-900/20" data-testid="headline">
                                    <p className="text-2xl font-bold text-gray-900 dark:text-gray-100">{data.full_name}</p>
                                    <p className="mt-1 text-lg text-gray-800 dark:text-gray-200">{position?.title ?? t('employee.no_job_title')}{department ? ` · ${department.name}` : ''}</p>
                                </div>
                                <ReviewList
                                    onEdit={wizard.goTo}
                                    rows={[
                                        { label: t('employee.phone'), value: data.phone, step: stepOf('personal') },
                                        { label: t('employee.hire_date'), value: data.hire_date, step: stepOf('employment') },
                                        ...(branches.length > 1 ? [{ label: t('employee.branch'), value: branch?.name ?? '', step: stepOf('employment') }] : []),
                                        { label: t('employee.employment_status'), value: t(`employee.status_${data.employment_status}`), step: stepOf('employment') },
                                        { label: t('employee.link_account'), value: linkedUser ? linkedUser.name : t('employee.no_account'), step: stepOf('other') },
                                    ]}
                                />
                                {data.user_id && <Alert tone="info" title={t('employee.linking_note_title')}>{t('employee.linking_note_body')}</Alert>}
                            </div>
                        )}
                    </WizardShell>
                </Card>
            </Page>
        </AuthenticatedLayout>
    );
}
