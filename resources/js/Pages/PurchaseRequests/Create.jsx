import { BackLink, Button, ChoiceGroup, Field, Input, Page, ReviewList, Select, useWizard, WizardShell } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PlusIcon, TrashIcon } from '@heroicons/react/24/outline';
import { useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

const emptyLine = () => ({ item_id: '', description: '', quantity: '1', estimated_unit_price: '', notes: '' });
const money = (n) => Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

export default function Create({ items }) {
    const { t } = useTranslation();
    const { data, setData, post, transform, processing, errors } = useForm({
        department: '', purpose: '', priority: '', notes: '', items: [emptyLine()],
    });

    const setLine = (index, patch) => setData('items', data.items.map((line, i) => (i === index ? { ...line, ...patch } : line)));
    const addLine = () => setData('items', [...data.items, emptyLine()]);
    const removeLine = (index) => setData('items', data.items.filter((_, i) => i !== index));
    const pickCatalogItem = (index, itemId) => {
        const item = items.find((i) => String(i.id) === String(itemId));
        setLine(index, { item_id: itemId, description: item ? item.name : data.items[index].description });
    };
    const lineTotal = (line) => (line.estimated_unit_price !== '' ? Number(line.quantity || 0) * Number(line.estimated_unit_price) : null);
    const grandTotal = data.items.reduce((sum, l) => sum + (lineTotal(l) ?? 0), 0);

    const linesValid = data.items.length > 0 && data.items.every((l) => l.description.trim() !== '' && Number(l.quantity) > 0);

    const steps = [
        {
            key: 'where',
            label: t('pr.step_where'),
            description: t('pr.step_where_desc'),
            fields: ['department', 'purpose', 'priority'],
            isValid: () => data.purpose.trim() !== '' && data.priority !== '',
            whyBlocked: data.purpose.trim() === '' ? t('pr.why_purpose') : t('pr.why_priority'),
        },
        {
            key: 'items',
            label: t('pr.step_items'),
            description: t('pr.step_items_desc'),
            fields: ['items'],
            isValid: () => linesValid,
            whyBlocked: t('pr.why_items'),
        },
        { key: 'review', label: t('pr.step_review'), description: t('pr.step_review_desc'), fields: [] },
    ];

    const submitAs = (action) => {
        transform((d) => ({ ...d, action }));
        post(route('purchase-requests.store'));
    };

    const wizard = useWizard({ steps, errors, onSubmit: () => submitAs('submit') });

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">{t('pr.create_title')}</h2>}>
            <Page title={t('pr.create_title')} width="default" back={<BackLink href={route('purchase-requests.index')}>{t('pr.back_to_list')}</BackLink>}>
                <WizardShell wizard={wizard} finalLabel={t('pr.submit_button')} processing={processing}>
                    {wizard.step.key === 'where' && (
                        <div className="space-y-5">
                            <Field id="pr_department" label={t('pr.department')} optional error={errors.department}>
                                <Input value={data.department} onChange={(e) => setData('department', e.target.value)} maxLength={255} autoComplete="off" />
                            </Field>
                            <Field id="pr_purpose" label={t('pr.purpose')} hint={t('pr.purpose_hint')} error={errors.purpose}>
                                <Input value={data.purpose} onChange={(e) => setData('purpose', e.target.value)} maxLength={255} autoComplete="off" />
                            </Field>
                            <ChoiceGroup
                                heading={t('pr.priority')}
                                layout="segmented"
                                value={data.priority}
                                onChange={(v) => setData('priority', v)}
                                options={[
                                    { value: 'low', label: t('pr.priority_low') },
                                    { value: 'normal', label: t('pr.priority_normal') },
                                    { value: 'urgent', label: t('pr.priority_urgent') },
                                ]}
                            />
                        </div>
                    )}

                    {wizard.step.key === 'items' && (
                        <div className="space-y-4">
                            {data.items.map((line, i) => (
                                <div key={i} className="space-y-3 rounded-xl border-2 border-stone-200 p-4 dark:border-stone-700">
                                    <div className="flex items-start justify-between gap-2">
                                        <p className="text-base font-semibold text-stone-900 dark:text-stone-100">{t('pr.line_n', { n: i + 1 })}</p>
                                        {data.items.length > 1 && (
                                            <Button type="button" variant="quiet" size="md" icon={TrashIcon} onClick={() => removeLine(i)}>{t('pr.remove_line')}</Button>
                                        )}
                                    </div>
                                    <Field id={`pr_catalog_${i}`} label={t('pr.catalog_item')} optional hint={t('pr.catalog_item_hint')}>
                                        <Select value={line.item_id} onChange={(e) => pickCatalogItem(i, e.target.value)}>
                                            <option value="">{t('pr.not_a_catalog_item')}</option>
                                            {items.map((it) => <option key={it.id} value={it.id}>{it.name}</option>)}
                                        </Select>
                                    </Field>
                                    <Field id={`pr_desc_${i}`} label={t('pr.description')} error={errors[`items.${i}.description`]}>
                                        <Input value={line.description} onChange={(e) => setLine(i, { description: e.target.value })} maxLength={255} autoComplete="off" />
                                    </Field>
                                    <Field id={`pr_qty_${i}`} label={t('pr.quantity')} error={errors[`items.${i}.quantity`]}>
                                        <Input inputMode="numeric" value={line.quantity} onChange={(e) => setLine(i, { quantity: e.target.value })} autoComplete="off" />
                                    </Field>
                                    <Field id={`pr_price_${i}`} label={t('pr.estimated_unit_price')} optional hint={t('pr.estimated_unit_price_hint')} error={errors[`items.${i}.estimated_unit_price`]}>
                                        <Input inputMode="decimal" value={line.estimated_unit_price} onChange={(e) => setLine(i, { estimated_unit_price: e.target.value })} autoComplete="off" />
                                    </Field>
                                    {lineTotal(line) !== null && (
                                        <p className="text-base font-semibold text-stone-900 dark:text-stone-100">{t('pr.line_total', { amount: money(lineTotal(line)) })}</p>
                                    )}
                                </div>
                            ))}
                            <Button type="button" variant="secondary" icon={PlusIcon} onClick={addLine}>{t('pr.add_line')}</Button>
                        </div>
                    )}

                    {wizard.step.key === 'review' && (
                        <div className="space-y-5">
                            <ReviewList
                                onEdit={wizard.goTo}
                                rows={[
                                    { label: t('pr.purpose'), value: data.purpose, step: 0 },
                                    { label: t('pr.priority'), value: t(`pr.priority_${data.priority}`), step: 0 },
                                    ...data.items.map((l, i) => ({ label: t('pr.line_n', { n: i + 1 }), value: `${l.quantity} × ${l.description}${lineTotal(l) !== null ? ` -- ${money(lineTotal(l))} ETB` : ''}`, step: 1 })),
                                    ...(grandTotal > 0 ? [{ label: t('pr.estimated_grand_total'), value: `${money(grandTotal)} ETB` }] : []),
                                ]}
                            />
                            <Button type="button" variant="secondary" fullWidth busy={processing} onClick={() => submitAs('draft')}>{t('pr.save_draft_button')}</Button>
                        </div>
                    )}
                </WizardShell>
            </Page>
        </AuthenticatedLayout>
    );
}
