import Modal from '@/Components/Modal';
import { Button, Field, Input } from '@/Components/ui';
import { useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

export default function PayrollTaxBracketDialog({ open, bracket, onClose }) {
    const { t } = useTranslation();
    const editing = Boolean(bracket);
    const { data, setData, post, put, transform, processing, errors, reset } = useForm({
        min_amount: bracket?.min_amount != null ? String(bracket.min_amount) : '',
        max_amount: bracket?.max_amount != null ? String(bracket.max_amount) : '',
        rate: bracket?.rate != null ? String(bracket.rate * 100) : '',
        deduction: bracket?.deduction != null ? String(bracket.deduction) : '',
    });

    const submit = (e) => {
        e.preventDefault();
        // The form shows the rate as a human percentage (e.g. "15"); the server stores a decimal
        // fraction (0.15). transform() converts it right before sending, without touching what's displayed.
        transform((d) => ({ ...d, rate: d.rate === '' ? '' : String(Number(d.rate) / 100), max_amount: d.max_amount === '' ? null : d.max_amount }));
        const options = { preserveScroll: true, onSuccess: () => { reset(); onClose(); } };
        if (editing) {
            put(route('payroll-tax-brackets.update', bracket.id), options);
        } else {
            post(route('payroll-tax-brackets.store'), options);
        }
    };

    return (
        <Modal show={open} onClose={onClose} maxWidth="lg">
            {open && (
                <form onSubmit={submit} className="space-y-6 p-6" data-testid="payroll-bracket-form">
                    <h2 className="text-2xl font-bold text-gray-900 dark:text-gray-100">{editing ? t('payroll.edit_bracket_title') : t('payroll.add_bracket_title')}</h2>
                    <Field id="bracket_min" label={t('payroll.bracket_min_label')} error={errors.min_amount}>
                        <Input inputMode="decimal" value={data.min_amount} onChange={(e) => setData('min_amount', e.target.value)} />
                    </Field>
                    <Field id="bracket_max" label={t('payroll.bracket_max_label')} hint={t('payroll.bracket_max_hint')} error={errors.max_amount}>
                        <Input inputMode="decimal" value={data.max_amount} onChange={(e) => setData('max_amount', e.target.value)} />
                    </Field>
                    <Field id="bracket_rate" label={t('payroll.bracket_rate_label')} error={errors.rate}>
                        <Input inputMode="decimal" value={data.rate} onChange={(e) => setData('rate', e.target.value)} />
                    </Field>
                    <Field id="bracket_deduction" label={t('payroll.bracket_deduction_label')} error={errors.deduction}>
                        <Input inputMode="decimal" value={data.deduction} onChange={(e) => setData('deduction', e.target.value)} />
                    </Field>
                    <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        <Button type="button" variant="secondary" onClick={onClose}>{t('common.cancel')}</Button>
                        <Button type="submit" busy={processing} disabled={!data.min_amount || !data.rate}>{t('payroll.save_bracket_button')}</Button>
                    </div>
                </form>
            )}
        </Modal>
    );
}
