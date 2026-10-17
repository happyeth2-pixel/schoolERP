import { BackLink, Button, Card, Field, FlashMessages, Input, Page, SectionHeader, Select, TextArea } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

export default function Edit({ employee, departments, positions, branches, linkableUsers, employmentStatuses, employmentTypes }) {
    const { t } = useTranslation();
    const form = useForm({
        full_name: employee.full_name, phone: employee.phone, email: employee.email ?? '',
        gender: employee.gender ?? '', date_of_birth: employee.date_of_birth ?? '', photo: null,
        hire_date: employee.hire_date, department_id: employee.department_id ? String(employee.department_id) : '',
        position_id: employee.position_id ? String(employee.position_id) : '', branch_id: employee.branch_id ? String(employee.branch_id) : '',
        employment_status: employee.employment_status, employment_type: employee.employment_type,
        qualifications: employee.qualifications ?? '', address: employee.address ?? '',
        emergency_contact_name: employee.emergency_contact_name ?? '', emergency_contact_phone: employee.emergency_contact_phone ?? '',
        notes: employee.notes ?? '', user_id: employee.user_id ? String(employee.user_id) : '',
    });
    const { data, setData, errors, processing, clearErrors } = form;
    const set = (k) => (v) => { clearErrors(k); setData(k, v); };
    const positionsForDepartment = data.department_id ? positions.filter((p) => String(p.department_id) === data.department_id) : positions;

    const submit = (e) => {
        e.preventDefault();
        form.put(route('employees.update', employee.id));
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">{t('employee.edit_title', { name: employee.full_name })}</h2>}>
            <Page title={t('employee.edit_title', { name: employee.full_name })} back={<BackLink href={route('employees.show', employee.id)}>{t('employee.back_to_profile')}</BackLink>}>
                <FlashMessages />
                <form onSubmit={submit} className="space-y-6">
                    <Card>
                        <SectionHeader as="h3" title={t('employee.section_personal')} />
                        <div className="space-y-5">
                            <Field id="emp_full_name" label={t('employee.full_name')} error={errors.full_name}>
                                <Input value={data.full_name} onChange={(e) => set('full_name')(e.target.value)} maxLength={255} />
                            </Field>
                            <Field id="emp_phone" label={t('employee.phone')} error={errors.phone}>
                                <Input value={data.phone} onChange={(e) => set('phone')(e.target.value)} maxLength={30} />
                            </Field>
                            <Field id="emp_email" label={t('employee.email')} error={errors.email}>
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
                    </Card>

                    <Card>
                        <SectionHeader as="h3" title={t('employee.section_employment')} />
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
                            <Field id="emp_position" label={t('employee.job_title')} error={errors.position_id}>
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
                    </Card>

                    <Card>
                        <SectionHeader as="h3" title={t('employee.section_other')} />
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
                            <Field id="emp_notes" label={t('employee.notes')} error={errors.notes}>
                                <TextArea value={data.notes} onChange={(e) => set('notes')(e.target.value)} rows={3} maxLength={2000} />
                            </Field>
                        </div>
                    </Card>

                    <Button type="submit" busy={processing}>{t('employee.save_button')}</Button>
                </form>
            </Page>
        </AuthenticatedLayout>
    );
}
