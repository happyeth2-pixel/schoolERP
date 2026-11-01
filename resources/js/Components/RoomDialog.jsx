import Modal from '@/Components/Modal';
import { Button, Field, Input, Select } from '@/Components/ui';
import { useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

export default function RoomDialog({ open, room, branches, fixedBranch, onClose }) {
    const { t } = useTranslation();
    const editing = Boolean(room);
    const { data, setData, post, put, processing, errors, reset } = useForm({
        branch_id: fixedBranch?.id ?? (branches[0]?.id ?? ''),
        name: room?.name ?? '',
        capacity: room?.capacity != null ? String(room.capacity) : '',
    });

    const submit = (e) => {
        e.preventDefault();
        const onSuccess = () => { reset(); onClose(); };
        if (editing) {
            put(route('rooms.update', room.id), { preserveScroll: true, onSuccess });
        } else {
            post(route('rooms.store'), { preserveScroll: true, onSuccess });
        }
    };

    return (
        <Modal show={open} onClose={onClose} maxWidth="lg">
            {open && (
                <form onSubmit={submit} className="space-y-6 p-6" data-testid="room-form">
                    <h2 className="text-2xl font-bold text-stone-900 dark:text-stone-100">{editing ? t('timetable.edit_room_title') : t('timetable.add_room_title')}</h2>
                    {!editing && (fixedBranch ? (
                        <p className="text-base text-stone-800 dark:text-stone-200" data-testid="fixed-branch">{fixedBranch.name}</p>
                    ) : (
                        <Field id="room_branch" label={t('payroll.branch_label')} error={errors.branch_id}>
                            <Select id="room_branch" value={data.branch_id} onChange={(e) => setData('branch_id', e.target.value)}>
                                {branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
                            </Select>
                        </Field>
                    ))}
                    <Field id="room_name" label={t('timetable.room_name_label')} error={errors.name}>
                        <Input value={data.name} onChange={(e) => setData('name', e.target.value)} maxLength={255} autoComplete="off" />
                    </Field>
                    <Field id="room_capacity" label={t('timetable.room_capacity_label')} optional error={errors.capacity}>
                        <Input inputMode="numeric" value={data.capacity} onChange={(e) => setData('capacity', e.target.value)} />
                    </Field>
                    <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        <Button type="button" variant="secondary" onClick={onClose}>{t('common.cancel')}</Button>
                        <Button type="submit" busy={processing} disabled={!data.name}>{t('timetable.save_room_button')}</Button>
                    </div>
                </form>
            )}
        </Modal>
    );
}
