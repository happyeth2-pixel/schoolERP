import Modal from '@/Components/Modal';
import DateInput from '@/Components/DateInput';
import { Button, Field, Input, Select, TextArea } from '@/Components/ui';
import { useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

const CONTRACT_TYPES = ['permanent', 'fixed_term', 'probationary', 'part_time'];
const STATUSES = ['draft', 'active', 'expiring', 'expired', 'terminated'];

export default function ContractDialog({ open, employeeId, contract, onClose }) {
    const { t } = useTranslation();
    const editing = Boolean(contract);
    const { data, setData, post, put, processing, errors, reset } = useForm({
        contract_type: contract?.contract_type ?? 'permanent',
        start_date: contract?.start_date ?? '',
        end_date: contract?.end_date ?? '',
        status: contract?.status ?? 'draft',
        salary_reference: contract?.salary_reference != null ? String(contract.salary_reference) : '',
        working_schedule: contract?.working_schedule ?? '',
        renewal_date: contract?.renewal_date ?? '',
        notes: contract?.notes ?? '',
        document: null,
    });

    const submit = (e) => {
        e.preventDefault();
        const onSuccess = () => { reset(); onClose(); };
        if (editing) {
            put(route('employment-contracts.update', contract.id), { preserveScroll: true, onSuccess });
        } else {
            post(route('employment-contracts.store', employeeId), { preserveScroll: true, forceFormData: true, onSuccess });
        }
    };

    return (
        <Modal show={open} onClose={onClose} maxWidth="lg">
            {open && (
                <form onSubmit={submit} className="space-y-6 p-6" data-testid="contract-form">
                    <h2 className="text-2xl font-bold text-stone-900 dark:text-stone-100">{editing ? t('contract.edit_title') : t('contract.add_title')}</h2>

                    <Field id="contract_type" label={t('contract.contract_type')} error={errors.contract_type}>
                        <Select id="contract_type" value={data.contract_type} onChange={(e) => setData('contract_type', e.target.value)}>
                            {CONTRACT_TYPES.map((ty) => <option key={ty} value={ty}>{t(`contract.type_${ty}`)}</option>)}
                        </Select>
                    </Field>
                    <Field id="contract_start" label={t('contract.start_date')} error={errors.start_date}>
                        <DateInput value={data.start_date} onChange={(v) => setData('start_date', v)} />
                    </Field>
                    <Field id="contract_end" label={t('contract.end_date')} hint={t('contract.end_date_hint')} error={errors.end_date}>
                        <DateInput value={data.end_date} onChange={(v) => setData('end_date', v)} />
                    </Field>
                    <Field id="contract_status" label={t('contract.status')} error={errors.status}>
                        <Select id="contract_status" value={data.status} onChange={(e) => setData('status', e.target.value)}>
                            {STATUSES.map((s) => <option key={s} value={s}>{t(`contract.status_${s}`)}</option>)}
                        </Select>
                    </Field>
                    <Field id="contract_salary" label={t('contract.salary_reference')} hint={t('contract.salary_reference_hint')} error={errors.salary_reference}>
                        <Input inputMode="decimal" value={data.salary_reference} onChange={(e) => setData('salary_reference', e.target.value)} />
                    </Field>
                    <Field id="contract_schedule" label={t('contract.working_schedule')} error={errors.working_schedule}>
                        <Input value={data.working_schedule} onChange={(e) => setData('working_schedule', e.target.value)} maxLength={255} />
                    </Field>
                    <Field id="contract_renewal" label={t('contract.renewal_date')} hint={t('contract.renewal_date_hint')} error={errors.renewal_date}>
                        <DateInput value={data.renewal_date} onChange={(v) => setData('renewal_date', v)} />
                    </Field>
                    <Field id="contract_document" label={t('contract.document')} hint={t('contract.document_hint')} error={errors.document}>
                        <input id="contract_document" type="file" accept="application/pdf" onChange={(e) => setData('document', e.target.files[0] ?? null)} className="block w-full text-base" />
                    </Field>
                    <Field id="contract_notes" label={t('contract.notes')} error={errors.notes}>
                        <TextArea value={data.notes} onChange={(e) => setData('notes', e.target.value)} rows={2} maxLength={2000} />
                    </Field>

                    <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        <Button type="button" variant="secondary" onClick={onClose}>{t('common.cancel')}</Button>
                        <Button type="submit" busy={processing} disabled={!data.contract_type || !data.start_date}>{t('contract.save_button')}</Button>
                    </div>
                </form>
            )}
        </Modal>
    );
}
