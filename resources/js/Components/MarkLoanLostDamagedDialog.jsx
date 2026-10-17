import Modal from '@/Components/Modal';
import { Button, Field, Input, Select, TextArea } from '@/Components/ui';
import { useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

export default function MarkLoanLostDamagedDialog({ open, loan, onClose }) {
    const { t } = useTranslation();
    const { data, setData, post, processing, errors, reset } = useForm({
        status: 'lost',
        fine_amount: '',
        reason: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('library.loans.mark', loan.id), { preserveScroll: true, onSuccess: () => { reset(); onClose(); } });
    };

    return (
        <Modal show={open} onClose={onClose} maxWidth="lg">
            {open && loan && (
                <form onSubmit={submit} className="space-y-6 p-6" data-testid="mark-lost-damaged-form">
                    <h2 className="text-2xl font-bold text-gray-900 dark:text-gray-100">{t('library.mark_lost_damaged_title', { book: loan.book_title })}</h2>
                    <Field id="mark_status" label={t('library.what_happened_label')} error={errors.status}>
                        <Select id="mark_status" value={data.status} onChange={(e) => setData('status', e.target.value)}>
                            <option value="lost">{t('library.status_lost')}</option>
                            <option value="damaged">{t('library.status_damaged')}</option>
                        </Select>
                    </Field>
                    <Field id="mark_reason" label={t('library.reason_label')} error={errors.reason}>
                        <TextArea value={data.reason} onChange={(e) => setData('reason', e.target.value)} rows={2} maxLength={1000} />
                    </Field>
                    <Field id="mark_fine" label={t('library.fine_amount_label')} optional hint={t('library.fine_amount_hint')} error={errors.fine_amount}>
                        <Input inputMode="decimal" value={data.fine_amount} onChange={(e) => setData('fine_amount', e.target.value)} />
                    </Field>
                    <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        <Button type="button" variant="secondary" onClick={onClose}>{t('common.cancel')}</Button>
                        <Button type="submit" variant="danger" busy={processing} disabled={!data.reason}>{t('library.close_loan_button')}</Button>
                    </div>
                </form>
            )}
        </Modal>
    );
}
