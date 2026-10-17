import Modal from '@/Components/Modal';
import { Button, ChoiceGroup, Field, Select, TextArea } from '@/Components/ui';
import { useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

// One shared shape for every asset action: pick a person, OR pick a store, OR just give a reason -- each
// posts to its own route, named by the action itself so the outcome is never a guess.

export function PersonDialog({ open, asset, people, routeName, title, buttonLabel, onClose }) {
    const { t } = useTranslation();
    const { data, setData, post, processing, errors, reset } = useForm({ user_id: '', notes: '' });

    const submit = (e) => {
        e.preventDefault();
        post(route(routeName, asset.id), { preserveScroll: true, onSuccess: () => { reset(); onClose(); } });
    };

    return (
        <Modal show={open} onClose={onClose} maxWidth="lg">
            {open && (
                <form onSubmit={submit} className="space-y-6 p-6" data-testid="asset-person-form">
                    <h2 className="text-2xl font-bold text-gray-900 dark:text-gray-100">{title}</h2>
                    <Field id="asset_person" label={t('asset.person')} error={errors.user_id}>
                        <Select value={data.user_id} onChange={(e) => setData('user_id', e.target.value)}>
                            <option value="">--</option>
                            {people.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                        </Select>
                    </Field>
                    <Field id="asset_person_notes" label={t('asset.notes')} optional error={errors.notes}>
                        <TextArea id="asset_person_notes" value={data.notes} onChange={(e) => setData('notes', e.target.value)} />
                    </Field>
                    <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        <Button type="button" variant="secondary" onClick={onClose}>{t('common.cancel')}</Button>
                        <Button type="submit" busy={processing} disabled={!data.user_id}>{buttonLabel}</Button>
                    </div>
                </form>
            )}
        </Modal>
    );
}

export function StoreDialog({ open, asset, stores, routeName, title, buttonLabel, onClose }) {
    const { t } = useTranslation();
    const { data, setData, post, processing, errors, reset } = useForm({ store_id: '', notes: '' });

    const submit = (e) => {
        e.preventDefault();
        post(route(routeName, asset.id), { preserveScroll: true, onSuccess: () => { reset(); onClose(); } });
    };

    return (
        <Modal show={open} onClose={onClose} maxWidth="lg">
            {open && (
                <form onSubmit={submit} className="space-y-6 p-6" data-testid="asset-store-form">
                    <h2 className="text-2xl font-bold text-gray-900 dark:text-gray-100">{title}</h2>
                    <Field id="asset_store" label={t('asset.store')} error={errors.store_id}>
                        <Select value={data.store_id} onChange={(e) => setData('store_id', e.target.value)}>
                            <option value="">--</option>
                            {stores.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                        </Select>
                    </Field>
                    <Field id="asset_store_notes" label={t('asset.notes')} optional error={errors.notes}>
                        <TextArea id="asset_store_notes" value={data.notes} onChange={(e) => setData('notes', e.target.value)} />
                    </Field>
                    <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        <Button type="button" variant="secondary" onClick={onClose}>{t('common.cancel')}</Button>
                        <Button type="submit" busy={processing} disabled={!data.store_id}>{buttonLabel}</Button>
                    </div>
                </form>
            )}
        </Modal>
    );
}

export function NotesDialog({ open, asset, routeName, title, buttonLabel, tone = 'primary', required = true, onClose }) {
    const { t } = useTranslation();
    const { data, setData, post, processing, errors, reset } = useForm({ notes: '' });

    const submit = (e) => {
        e.preventDefault();
        post(route(routeName, asset.id), { preserveScroll: true, onSuccess: () => { reset(); onClose(); } });
    };

    return (
        <Modal show={open} onClose={onClose} maxWidth="lg">
            {open && (
                <form onSubmit={submit} className="space-y-6 p-6" data-testid="asset-notes-form">
                    <h2 className="text-2xl font-bold text-gray-900 dark:text-gray-100">{title}</h2>
                    <Field id="asset_notes" label={t('asset.reason')} optional={!required} error={errors.notes}>
                        <TextArea id="asset_notes" value={data.notes} onChange={(e) => setData('notes', e.target.value)} />
                    </Field>
                    <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        <Button type="button" variant="secondary" onClick={onClose}>{t('common.cancel')}</Button>
                        <Button type="submit" variant={tone} busy={processing} disabled={required && data.notes.trim() === ''}>{buttonLabel}</Button>
                    </div>
                </form>
            )}
        </Modal>
    );
}

export function RepairCompleteDialog({ open, asset, onClose }) {
    const { t } = useTranslation();
    const { data, setData, post, processing, errors, reset } = useForm({ outcome: '', notes: '' });

    const submit = (e) => {
        e.preventDefault();
        post(route('assets.repair-complete', asset.id), { preserveScroll: true, onSuccess: () => { reset(); onClose(); } });
    };

    return (
        <Modal show={open} onClose={onClose} maxWidth="lg">
            {open && (
                <form onSubmit={submit} className="space-y-6 p-6" data-testid="asset-repair-complete-form">
                    <h2 className="text-2xl font-bold text-gray-900 dark:text-gray-100">{t('asset.repair_complete_title')}</h2>
                    <ChoiceGroup
                        heading={t('asset.repair_outcome')}
                        layout="segmented"
                        value={data.outcome}
                        onChange={(v) => setData('outcome', v)}
                        options={[
                            { value: 'fixed', label: t('asset.repair_fixed') },
                            { value: 'damaged', label: t('asset.repair_damaged') },
                        ]}
                    />
                    <Field id="repair_notes" label={t('asset.notes')} optional error={errors.notes}>
                        <TextArea id="repair_notes" value={data.notes} onChange={(e) => setData('notes', e.target.value)} />
                    </Field>
                    <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        <Button type="button" variant="secondary" onClick={onClose}>{t('common.cancel')}</Button>
                        <Button type="submit" busy={processing} disabled={!data.outcome}>{t('asset.repair_complete_button')}</Button>
                    </div>
                </form>
            )}
        </Modal>
    );
}
