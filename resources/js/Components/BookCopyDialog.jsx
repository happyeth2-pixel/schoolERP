import Modal from '@/Components/Modal';
import { Button, Field, Input, Select } from '@/Components/ui';
import { useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

export default function BookCopyDialog({ open, bookId, branches, canPostSchoolWide, onClose }) {
    const { t } = useTranslation();
    const { data, setData, post, processing, errors, reset } = useForm({
        branch_id: canPostSchoolWide ? '' : (branches[0]?.id ?? ''),
        copy_number: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('library-books.copies.store', bookId), { preserveScroll: true, onSuccess: () => { reset(); onClose(); } });
    };

    return (
        <Modal show={open} onClose={onClose} maxWidth="lg">
            {open && (
                <form onSubmit={submit} className="space-y-6 p-6" data-testid="book-copy-form">
                    <h2 className="text-2xl font-bold text-stone-900 dark:text-stone-100">{t('library.add_copy_title')}</h2>
                    {canPostSchoolWide && (
                        <Field id="copy_branch" label={t('library.branch_label')} error={errors.branch_id}>
                            <Select id="copy_branch" value={data.branch_id} onChange={(e) => setData('branch_id', e.target.value)}>
                                <option value="" disabled>{t('timetable.pick_option')}</option>
                                {branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
                            </Select>
                        </Field>
                    )}
                    <Field id="copy_number" label={t('library.copy_number_label')} hint={t('library.copy_number_hint')} error={errors.copy_number}>
                        <Input value={data.copy_number} onChange={(e) => setData('copy_number', e.target.value)} maxLength={50} autoComplete="off" />
                    </Field>
                    <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        <Button type="button" variant="secondary" onClick={onClose}>{t('common.cancel')}</Button>
                        <Button type="submit" busy={processing} disabled={!data.copy_number || !data.branch_id}>{t('library.save_copy_button')}</Button>
                    </div>
                </form>
            )}
        </Modal>
    );
}
