import Modal from '@/Components/Modal';
import { Button, Field, Input, Select, TextArea } from '@/Components/ui';
import { useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

export default function LeaveAllocationDialog({ open, employeeId, leaveTypes, allocation, onClose }) {
    const { t } = useTranslation();
    const editing = Boolean(allocation);
    const { data, setData, post, put, processing, errors, reset } = useForm({
        leave_type_id: leaveTypes[0]?.id ?? '',
        year: allocation?.year ?? new Date().getFullYear(),
        allocated_days: allocation?.allocated_days != null ? String(allocation.allocated_days) : '',
        notes: '',
    });

    const submit = (e) => {
        e.preventDefault();
        const onSuccess = () => { reset(); onClose(); };
        if (editing) {
            put(route('leave-allocations.update', allocation.id), { preserveScroll: true, onSuccess });
        } else {
            post(route('leave-allocations.store', employeeId), { preserveScroll: true, onSuccess });
        }
    };

    return (
        <Modal show={open} onClose={onClose} maxWidth="lg">
            {open && (
                <form onSubmit={submit} className="space-y-6 p-6" data-testid="leave-allocation-form">
                    <h2 className="text-2xl font-bold text-gray-900 dark:text-gray-100">{editing ? t('leave.edit_allocation_title') : t('leave.add_allocation_title')}</h2>

                    {!editing && (
                        <Field id="alloc_type" label={t('leave.leave_type')} error={errors.leave_type_id}>
                            <Select id="alloc_type" value={data.leave_type_id} onChange={(e) => setData('leave_type_id', e.target.value)}>
                                {leaveTypes.map((lt) => <option key={lt.id} value={lt.id}>{lt.name}</option>)}
                            </Select>
                        </Field>
                    )}
                    {!editing && (
                        <Field id="alloc_year" label={t('leave.year')} error={errors.year}>
                            <Input type="number" value={data.year} onChange={(e) => setData('year', e.target.value)} />
                        </Field>
                    )}
                    <Field id="alloc_days" label={t('leave.allocated_days')} error={errors.allocated_days}>
                        <Input inputMode="decimal" value={data.allocated_days} onChange={(e) => setData('allocated_days', e.target.value)} />
                    </Field>
                    <Field id="alloc_notes" label={t('leave.notes')} error={errors.notes}>
                        <TextArea value={data.notes} onChange={(e) => setData('notes', e.target.value)} rows={2} maxLength={1000} />
                    </Field>

                    <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        <Button type="button" variant="secondary" onClick={onClose}>{t('common.cancel')}</Button>
                        <Button type="submit" busy={processing} disabled={!data.allocated_days}>{t('leave.save_allocation')}</Button>
                    </div>
                </form>
            )}
        </Modal>
    );
}
