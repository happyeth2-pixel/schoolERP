import Modal from '@/Components/Modal';
import { Alert, Button, Field, Input, Select, TextArea } from '@/Components/ui';
import { useForm } from '@inertiajs/react';
import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';

// Four focused dialogs, one per stock movement a person can start by hand -- the same "show the effect before
// saving" pattern as InvoiceAdjust's discount/waiver dialogs. Each posts straight to StockMovementController;
// the server is still what actually enforces "not enough stock" (a race with someone else's issue is only
// ever caught safely there, under a row lock -- see App\Services\Stock).

const StoreSelect = ({ id, stores, value, onChange, excludeId }) => (
    <Select id={id} value={value} onChange={onChange}>
        <option value="">--</option>
        {stores.filter((s) => s.id !== excludeId).map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
    </Select>
);

export function ReceiveDialog({ open, item, stores, onClose }) {
    const { t } = useTranslation();
    const { data, setData, post, processing, errors, reset, clearErrors } = useForm({ item_id: item?.id ?? '', store_id: stores[0]?.id ?? '', quantity: '', reference_number: '', reason: '' });

    const submit = (e) => {
        e.preventDefault();
        post(route('stock-movements.receive'), { preserveScroll: true, onSuccess: () => { reset(); onClose(); } });
    };

    return (
        <Modal show={open} onClose={onClose} maxWidth="lg">
            {open && (
                <form onSubmit={submit} className="space-y-6 p-6" data-testid="receive-form">
                    <h2 className="text-2xl font-bold text-stone-900 dark:text-stone-100">{t('inv.receive_title', { name: item?.name })}</h2>
                    <Field id="receive_store" label={t('inv.store')} error={errors.store_id}>
                        <StoreSelect id="receive_store" stores={stores} value={data.store_id} onChange={(e) => setData('store_id', e.target.value)} />
                    </Field>
                    <Field id="receive_quantity" label={t('inv.quantity', { unit: item?.unit })} error={errors.quantity}>
                        <Input inputMode="numeric" value={data.quantity} onChange={(e) => { clearErrors('quantity'); setData('quantity', e.target.value); }} autoComplete="off" />
                    </Field>
                    <Field id="receive_reference" label={t('inv.reference_number')} optional hint={t('inv.reference_hint')} error={errors.reference_number}>
                        <Input value={data.reference_number} onChange={(e) => setData('reference_number', e.target.value)} autoComplete="off" />
                    </Field>
                    <Field id="receive_reason" label={t('inv.reason')} optional error={errors.reason}>
                        <Input value={data.reason} onChange={(e) => setData('reason', e.target.value)} autoComplete="off" />
                    </Field>
                    <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        <Button type="button" variant="secondary" onClick={onClose}>{t('common.cancel')}</Button>
                        <Button type="submit" variant="success" busy={processing} disabled={!data.store_id || !data.quantity}>{t('inv.receive_button')}</Button>
                    </div>
                </form>
            )}
        </Modal>
    );
}

export function IssueDialog({ open, item, stores, onClose }) {
    const { t } = useTranslation();
    const { data, setData, post, processing, errors, reset, clearErrors } = useForm({ item_id: item?.id ?? '', store_id: stores[0]?.id ?? '', quantity: '', reason: '' });

    const submit = (e) => {
        e.preventDefault();
        post(route('stock-movements.issue'), { preserveScroll: true, onSuccess: () => { reset(); onClose(); } });
    };

    return (
        <Modal show={open} onClose={onClose} maxWidth="lg">
            {open && (
                <form onSubmit={submit} className="space-y-6 p-6" data-testid="issue-form">
                    <h2 className="text-2xl font-bold text-stone-900 dark:text-stone-100">{t('inv.issue_title', { name: item?.name })}</h2>
                    <Field id="issue_store" label={t('inv.store')} error={errors.store_id}>
                        <StoreSelect id="issue_store" stores={stores} value={data.store_id} onChange={(e) => setData('store_id', e.target.value)} />
                    </Field>
                    <Field id="issue_quantity" label={t('inv.quantity', { unit: item?.unit })} error={errors.quantity}>
                        <Input inputMode="numeric" value={data.quantity} onChange={(e) => { clearErrors('quantity'); setData('quantity', e.target.value); }} autoComplete="off" />
                    </Field>
                    <Field id="issue_reason" label={t('inv.issue_reason')} hint={t('inv.issue_reason_hint')} error={errors.reason}>
                        <Input value={data.reason} onChange={(e) => setData('reason', e.target.value)} autoComplete="off" />
                    </Field>
                    <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        <Button type="button" variant="secondary" onClick={onClose}>{t('common.cancel')}</Button>
                        <Button type="submit" variant="primary" busy={processing} disabled={!data.store_id || !data.quantity || data.reason.trim() === ''}>{t('inv.issue_button')}</Button>
                    </div>
                </form>
            )}
        </Modal>
    );
}

export function TransferDialog({ open, item, stores, onClose }) {
    const { t } = useTranslation();
    const { data, setData, post, processing, errors, reset, clearErrors } = useForm({ item_id: item?.id ?? '', from_store_id: stores[0]?.id ?? '', to_store_id: '', quantity: '', reason: '' });

    const submit = (e) => {
        e.preventDefault();
        post(route('stock-movements.transfer'), { preserveScroll: true, onSuccess: () => { reset(); onClose(); } });
    };

    return (
        <Modal show={open} onClose={onClose} maxWidth="lg">
            {open && (
                <form onSubmit={submit} className="space-y-6 p-6" data-testid="transfer-form">
                    <h2 className="text-2xl font-bold text-stone-900 dark:text-stone-100">{t('inv.transfer_title', { name: item?.name })}</h2>
                    <Field id="transfer_from" label={t('inv.from_store')} error={errors.from_store_id}>
                        <StoreSelect id="transfer_from" stores={stores} value={data.from_store_id} onChange={(e) => setData('from_store_id', e.target.value)} />
                    </Field>
                    <Field id="transfer_to" label={t('inv.to_store')} error={errors.to_store_id}>
                        <StoreSelect id="transfer_to" stores={stores} excludeId={Number(data.from_store_id)} value={data.to_store_id} onChange={(e) => setData('to_store_id', e.target.value)} />
                    </Field>
                    <Field id="transfer_quantity" label={t('inv.quantity', { unit: item?.unit })} error={errors.quantity}>
                        <Input inputMode="numeric" value={data.quantity} onChange={(e) => { clearErrors('quantity'); setData('quantity', e.target.value); }} autoComplete="off" />
                    </Field>
                    <Field id="transfer_reason" label={t('inv.reason')} optional error={errors.reason}>
                        <Input value={data.reason} onChange={(e) => setData('reason', e.target.value)} autoComplete="off" />
                    </Field>
                    <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        <Button type="button" variant="secondary" onClick={onClose}>{t('common.cancel')}</Button>
                        <Button type="submit" variant="primary" busy={processing} disabled={!data.from_store_id || !data.to_store_id || !data.quantity}>{t('inv.transfer_button')}</Button>
                    </div>
                </form>
            )}
        </Modal>
    );
}

export function AdjustDialog({ open, item, stores, balances, onClose }) {
    const { t } = useTranslation();
    const { data, setData, post, processing, errors, reset, clearErrors } = useForm({ item_id: item?.id ?? '', store_id: stores[0]?.id ?? '', physical_quantity: '', reason: '' });
    const byStore = useMemo(() => Object.fromEntries((balances ?? []).map((b) => [String(b.store_id), b.quantity])), [balances]);
    const current = byStore[String(data.store_id)] ?? 0;
    const diff = data.physical_quantity !== '' && !Number.isNaN(Number(data.physical_quantity)) ? Number(data.physical_quantity) - current : null;

    const submit = (e) => {
        e.preventDefault();
        post(route('stock-movements.adjust'), { preserveScroll: true, onSuccess: () => { reset(); onClose(); } });
    };

    return (
        <Modal show={open} onClose={onClose} maxWidth="lg">
            {open && (
                <form onSubmit={submit} className="space-y-6 p-6" data-testid="adjust-form">
                    <h2 className="text-2xl font-bold text-stone-900 dark:text-stone-100">{t('inv.adjust_title', { name: item?.name })}</h2>
                    <Field id="adjust_store" label={t('inv.store')} error={errors.store_id}>
                        <StoreSelect id="adjust_store" stores={stores} value={data.store_id} onChange={(e) => setData('store_id', e.target.value)} />
                    </Field>
                    <p className="text-base text-stone-700 dark:text-stone-300" data-testid="adjust-current">{t('inv.adjust_current', { count: current, unit: item?.unit })}</p>
                    <Field id="adjust_physical" label={t('inv.adjust_physical')} hint={t('inv.adjust_physical_hint')} error={errors.physical_quantity}>
                        <Input inputMode="numeric" value={data.physical_quantity} onChange={(e) => { clearErrors('physical_quantity'); setData('physical_quantity', e.target.value); }} autoComplete="off" />
                    </Field>
                    {diff !== null && diff !== 0 && (
                        <Alert tone={diff > 0 ? 'info' : 'warning'} title={diff > 0 ? t('inv.adjust_preview_up', { count: diff }) : t('inv.adjust_preview_down', { count: Math.abs(diff) })} />
                    )}
                    <Field id="adjust_reason" label={t('inv.adjust_reason')} error={errors.reason}>
                        <TextArea id="adjust_reason" value={data.reason} onChange={(e) => setData('reason', e.target.value)} />
                    </Field>
                    <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        <Button type="button" variant="secondary" onClick={onClose}>{t('common.cancel')}</Button>
                        <Button type="submit" variant="primary" busy={processing} disabled={!data.store_id || data.physical_quantity === '' || data.reason.trim() === '' || diff === 0}>{t('inv.adjust_button')}</Button>
                    </div>
                </form>
            )}
        </Modal>
    );
}
