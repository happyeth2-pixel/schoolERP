import { Button, ChoiceGroup, Field, FlashMessages, Input, Page, ReviewList, Select, useWizard, WizardShell } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PlusIcon, TrashIcon } from '@heroicons/react/24/outline';
import { useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

const emptyLine = () => ({ item_id: '', quantity: '', notes: '' });

export default function Create({ stores, items }) {
    const { t } = useTranslation();
    const { data, setData, post, transform, processing, errors } = useForm({
        store_id: stores.length === 1 ? String(stores[0].id) : '',
        department: '',
        purpose: '',
        priority: '',
        items: [emptyLine()],
    });

    const itemName = (id) => items.find((i) => String(i.id) === String(id))?.name ?? '';
    const itemUnit = (id) => items.find((i) => String(i.id) === String(id))?.unit ?? '';
    const setLine = (index, patch) => setData('items', data.items.map((line, i) => (i === index ? { ...line, ...patch } : line)));
    const addLine = () => setData('items', [...data.items, emptyLine()]);
    const removeLine = (index) => setData('items', data.items.filter((_, i) => i !== index));

    const linesValid = data.items.length > 0 && data.items.every((l) => l.item_id !== '' && Number(l.quantity) > 0);

    const steps = [
        {
            key: 'where',
            label: t('req.step_where'),
            description: t('req.step_where_desc'),
            fields: ['store_id', 'department', 'purpose', 'priority'],
            isValid: () => data.store_id !== '' && data.purpose.trim() !== '' && data.priority !== '',
            whyBlocked: data.store_id === '' ? t('req.why_store') : data.purpose.trim() === '' ? t('req.why_purpose') : t('req.why_priority'),
        },
        {
            key: 'items',
            label: t('req.step_items'),
            description: t('req.step_items_desc'),
            fields: ['items'],
            isValid: () => linesValid,
            whyBlocked: t('req.why_items'),
        },
        { key: 'review', label: t('req.step_review'), description: t('req.step_review_desc'), fields: [] },
    ];

    const submitAs = (action) => {
        transform((d) => ({ ...d, action }));
        post(route('requisitions.store'));
    };

    const wizard = useWizard({ steps, errors, onSubmit: () => submitAs('submit') });

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">{t('req.create_title')}</h2>}>
            <Page title={t('req.create_title')} width="default">
                <FlashMessages />
                <WizardShell wizard={wizard} finalLabel={t('req.submit_button')} processing={processing}>
                    {wizard.step.key === 'where' && (
                        <div className="space-y-5">
                            <Field id="req_store" label={t('req.store')} error={errors.store_id}>
                                <Select value={data.store_id} onChange={(e) => setData('store_id', e.target.value)}>
                                    <option value="">--</option>
                                    {stores.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                                </Select>
                            </Field>
                            <Field id="req_department" label={t('req.department')} optional error={errors.department}>
                                <Input value={data.department} onChange={(e) => setData('department', e.target.value)} maxLength={255} autoComplete="off" />
                            </Field>
                            <Field id="req_purpose" label={t('req.purpose')} hint={t('req.purpose_hint')} error={errors.purpose}>
                                <Input value={data.purpose} onChange={(e) => setData('purpose', e.target.value)} maxLength={255} autoComplete="off" />
                            </Field>
                            <ChoiceGroup
                                heading={t('req.priority')}
                                layout="segmented"
                                value={data.priority}
                                onChange={(v) => setData('priority', v)}
                                options={[
                                    { value: 'low', label: t('req.priority_low') },
                                    { value: 'normal', label: t('req.priority_normal') },
                                    { value: 'urgent', label: t('req.priority_urgent') },
                                ]}
                            />
                        </div>
                    )}

                    {wizard.step.key === 'items' && (
                        <div className="space-y-4">
                            {data.items.map((line, i) => (
                                <div key={i} className="space-y-3 rounded-xl border-2 border-gray-200 p-4 dark:border-gray-700">
                                    <div className="flex items-start justify-between gap-2">
                                        <p className="text-base font-semibold text-gray-900 dark:text-gray-100">{t('req.line_n', { n: i + 1 })}</p>
                                        {data.items.length > 1 && (
                                            <Button type="button" variant="quiet" size="md" icon={TrashIcon} onClick={() => removeLine(i)}>{t('req.remove_line')}</Button>
                                        )}
                                    </div>
                                    <Field id={`req_item_${i}`} label={t('req.item')} error={errors[`items.${i}.item_id`]}>
                                        <Select value={line.item_id} onChange={(e) => setLine(i, { item_id: e.target.value })}>
                                            <option value="">--</option>
                                            {items.map((it) => <option key={it.id} value={it.id}>{it.name}</option>)}
                                        </Select>
                                    </Field>
                                    <Field id={`req_qty_${i}`} label={t('req.quantity', { unit: itemUnit(line.item_id) })} error={errors[`items.${i}.quantity`]}>
                                        <Input inputMode="numeric" value={line.quantity} onChange={(e) => setLine(i, { quantity: e.target.value })} autoComplete="off" />
                                    </Field>
                                    <Field id={`req_notes_${i}`} label={t('req.line_notes')} optional error={errors[`items.${i}.notes`]}>
                                        <Input value={line.notes} onChange={(e) => setLine(i, { notes: e.target.value })} maxLength={255} autoComplete="off" />
                                    </Field>
                                </div>
                            ))}
                            <Button type="button" variant="secondary" icon={PlusIcon} onClick={addLine}>{t('req.add_line')}</Button>
                        </div>
                    )}

                    {wizard.step.key === 'review' && (
                        <div className="space-y-5">
                            <ReviewList
                                onEdit={wizard.goTo}
                                rows={[
                                    { label: t('req.store'), value: stores.find((s) => String(s.id) === data.store_id)?.name ?? '', step: 0 },
                                    { label: t('req.purpose'), value: data.purpose, step: 0 },
                                    { label: t('req.priority'), value: t(`req.priority_${data.priority}`), step: 0 },
                                    ...data.items.map((l, i) => ({ label: t('req.line_n', { n: i + 1 }), value: `${l.quantity} ${itemUnit(l.item_id)} -- ${itemName(l.item_id)}`, step: 1 })),
                                ]}
                            />
                            <Button type="button" variant="secondary" fullWidth busy={processing} onClick={() => submitAs('draft')}>{t('req.save_draft_button')}</Button>
                        </div>
                    )}
                </WizardShell>
            </Page>
        </AuthenticatedLayout>
    );
}
