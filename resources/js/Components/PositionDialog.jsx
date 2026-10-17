import Modal from '@/Components/Modal';
import { Button, Field, Input, Select, TextArea } from '@/Components/ui';
import { useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

export default function PositionDialog({ open, departments, position, onClose }) {
    const { t } = useTranslation();
    const editing = Boolean(position);
    const { data, setData, post, put, processing, errors, reset } = useForm({
        title: position?.title ?? '',
        department_id: position?.department_id ? String(position.department_id) : '',
        description: position?.description ?? '',
    });

    const submit = (e) => {
        e.preventDefault();
        const onSuccess = () => { reset(); onClose(); };
        if (editing) {
            put(route('positions.update', position.id), { preserveScroll: true, onSuccess });
        } else {
            post(route('positions.store'), { preserveScroll: true, onSuccess });
        }
    };

    return (
        <Modal show={open} onClose={onClose} maxWidth="lg">
            {open && (
                <form onSubmit={submit} className="space-y-6 p-6" data-testid="position-form">
                    <h2 className="text-2xl font-bold text-gray-900 dark:text-gray-100">{editing ? t('position.edit_title') : t('position.add_title')}</h2>
                    <Field id="pos_title" label={t('position.job_title')} error={errors.title}>
                        <Input value={data.title} onChange={(e) => setData('title', e.target.value)} maxLength={255} autoComplete="off" />
                    </Field>
                    <Field id="pos_department" label={t('position.department')} error={errors.department_id}>
                        <Select id="pos_department" value={data.department_id} onChange={(e) => setData('department_id', e.target.value)}>
                            <option value="">{t('position.no_department')}</option>
                            {departments.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
                        </Select>
                    </Field>
                    <Field id="pos_description" label={t('position.description')} error={errors.description}>
                        <TextArea value={data.description} onChange={(e) => setData('description', e.target.value)} rows={2} maxLength={1000} />
                    </Field>
                    <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        <Button type="button" variant="secondary" onClick={onClose}>{t('common.cancel')}</Button>
                        <Button type="submit" busy={processing} disabled={!data.title}>{t('position.save_button')}</Button>
                    </div>
                </form>
            )}
        </Modal>
    );
}
