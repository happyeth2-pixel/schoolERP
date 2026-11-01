import Modal from '@/Components/Modal';
import { Button, Field, Input, Select, TextArea } from '@/Components/ui';
import { useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

export default function DepartmentDialog({ open, branches, employees, department, onClose }) {
    const { t } = useTranslation();
    const editing = Boolean(department);
    const { data, setData, post, put, processing, errors, reset } = useForm({
        name: department?.name ?? '',
        branch_id: '',
        manager_employee_id: department?.manager_employee_id ? String(department.manager_employee_id) : '',
        notes: department?.notes ?? '',
    });

    const submit = (e) => {
        e.preventDefault();
        const onSuccess = () => { reset(); onClose(); };
        if (editing) {
            put(route('departments.update', department.id), { preserveScroll: true, onSuccess });
        } else {
            post(route('departments.store'), { preserveScroll: true, onSuccess });
        }
    };

    return (
        <Modal show={open} onClose={onClose} maxWidth="lg">
            {open && (
                <form onSubmit={submit} className="space-y-6 p-6" data-testid="department-form">
                    <h2 className="text-2xl font-bold text-stone-900 dark:text-stone-100">{editing ? t('department.edit_title') : t('department.add_title')}</h2>
                    <Field id="dept_name" label={t('department.name')} error={errors.name}>
                        <Input value={data.name} onChange={(e) => setData('name', e.target.value)} maxLength={255} autoComplete="off" />
                    </Field>
                    {!editing && branches.length > 0 && (
                        <Field id="dept_branch" label={t('department.branch')} hint={t('department.branch_hint')} error={errors.branch_id}>
                            <Select id="dept_branch" value={data.branch_id} onChange={(e) => setData('branch_id', e.target.value)}>
                                <option value="">{t('department.org_wide')}</option>
                                {branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
                            </Select>
                        </Field>
                    )}
                    {editing && (
                        <Field id="dept_manager" label={t('department.manager')} error={errors.manager_employee_id}>
                            <Select id="dept_manager" value={data.manager_employee_id} onChange={(e) => setData('manager_employee_id', e.target.value)}>
                                <option value="">{t('department.no_manager')}</option>
                                {employees.map((e) => <option key={e.id} value={e.id}>{e.full_name}</option>)}
                            </Select>
                        </Field>
                    )}
                    <Field id="dept_notes" label={t('department.notes')} error={errors.notes}>
                        <TextArea value={data.notes} onChange={(e) => setData('notes', e.target.value)} rows={2} maxLength={1000} />
                    </Field>
                    <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        <Button type="button" variant="secondary" onClick={onClose}>{t('common.cancel')}</Button>
                        <Button type="submit" busy={processing} disabled={!data.name}>{t('department.save_button')}</Button>
                    </div>
                </form>
            )}
        </Modal>
    );
}
