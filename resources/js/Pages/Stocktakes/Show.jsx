import { BackLink, Button, Card, Field, FlashMessages, Input, Page, SectionHeader, StatusBadge } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

export default function Show({ stocktake, lines, canManage }) {
    const { t } = useTranslation();
    const initial = Object.fromEntries(lines.map((l) => [l.id, { physical_quantity: l.physical_quantity === null ? '' : String(l.physical_quantity), reason: l.reason ?? '' }]));
    const { data, setData, post, processing, errors } = useForm({ counts: initial });

    const setLine = (id, patch) => setData('counts', { ...data.counts, [id]: { ...data.counts[id], ...patch } });
    const diffOf = (line) => {
        const v = data.counts[line.id].physical_quantity;
        return v === '' ? null : Number(v) - line.system_quantity;
    };

    const saveCounts = (e) => {
        e.preventDefault();
        post(route('stocktakes.save-counts', stocktake.id), { preserveScroll: true });
    };

    const complete = () => {
        post(route('stocktakes.complete', stocktake.id));
    };

    const readOnly = !canManage;
    const allCounted = lines.every((l) => data.counts[l.id].physical_quantity !== '');
    const everyDifferenceExplained = lines.every((l) => diffOf(l) === 0 || diffOf(l) === null || data.counts[l.id].reason.trim() !== '');

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">{stocktake.store_name}</h2>}>
            <Page title={t('stocktake.show_title', { store: stocktake.store_name })} back={<BackLink href={route('stocktakes.index')}>{t('stocktake.back_to_list')}</BackLink>}>
                <FlashMessages />

                <Card>
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <p className="text-base text-stone-700 dark:text-stone-300">{t('stocktake.started_by_x', { name: stocktake.started_by, date: stocktake.started_at })}</p>
                        <StatusBadge tone={stocktake.status === 'completed' ? 'success' : 'warning'} label={t(`stocktake.status_${stocktake.status}`)} />
                    </div>
                    {stocktake.status === 'completed' && (
                        <p className="mt-2 text-base text-stone-700 dark:text-stone-300">{t('stocktake.completed_by_x', { name: stocktake.completed_by, date: stocktake.completed_at })}</p>
                    )}
                </Card>

                <Card as="form" onSubmit={saveCounts}>
                    <SectionHeader as="h3" title={t('stocktake.lines_title')} description={t('stocktake.lines_desc')} />
                    <ul className="divide-y divide-stone-200 dark:divide-stone-700" data-testid="stocktake-lines">
                        {lines.map((line) => {
                            const diff = diffOf(line);
                            return (
                                <li key={line.id} className="py-4" data-testid="stocktake-line-row">
                                    <div className="flex flex-wrap items-center justify-between gap-3">
                                        <div>
                                            <p className="text-base font-semibold text-stone-900 dark:text-stone-100">{line.item_name}</p>
                                            <p className="text-sm text-stone-600 dark:text-stone-400">{t('stocktake.system_quantity', { count: line.system_quantity, unit: line.unit })}</p>
                                        </div>
                                        {readOnly ? (
                                            <p className="text-lg font-bold text-stone-900 dark:text-stone-100">{line.physical_quantity ?? '—'} {line.unit}</p>
                                        ) : (
                                            <div className="w-32">
                                                <Field id={`count_${line.id}`} label={t('stocktake.physical_quantity')} error={errors[`counts.${line.id}.physical_quantity`]}>
                                                    <Input inputMode="numeric" value={data.counts[line.id].physical_quantity} onChange={(e) => setLine(line.id, { physical_quantity: e.target.value })} autoComplete="off" />
                                                </Field>
                                            </div>
                                        )}
                                    </div>
                                    {diff !== null && diff !== 0 && (
                                        <p className={`mt-2 text-sm font-semibold ${diff > 0 ? 'text-emerald-700 dark:text-emerald-400' : 'text-red-700 dark:text-red-400'}`}>
                                            {diff > 0 ? t('stocktake.diff_up', { count: diff }) : t('stocktake.diff_down', { count: Math.abs(diff) })}
                                        </p>
                                    )}
                                    {!readOnly && diff !== null && diff !== 0 && (
                                        <div className="mt-2">
                                            <Field id={`reason_${line.id}`} label={t('stocktake.reason')} error={errors[`counts.${line.id}.reason`]}>
                                                <Input value={data.counts[line.id].reason} onChange={(e) => setLine(line.id, { reason: e.target.value })} maxLength={255} autoComplete="off" />
                                            </Field>
                                        </div>
                                    )}
                                    {readOnly && line.reason && <p className="mt-1 text-sm text-stone-600 dark:text-stone-400">{line.reason}</p>}
                                </li>
                            );
                        })}
                    </ul>

                    {!readOnly && (
                        <div className="mt-6 flex flex-col gap-3 sm:flex-row">
                            <Button type="submit" variant="secondary" fullWidth busy={processing}>{t('stocktake.save_button')}</Button>
                            <Button type="button" fullWidth busy={processing} disabled={!allCounted || !everyDifferenceExplained} onClick={complete}>{t('stocktake.complete_button')}</Button>
                        </div>
                    )}
                    {!readOnly && (!allCounted || !everyDifferenceExplained) && (
                        <p className="mt-3 text-sm text-stone-600 dark:text-stone-400">{!allCounted ? t('stocktake.why_not_counted') : t('stocktake.why_no_reason')}</p>
                    )}
                </Card>
            </Page>
        </AuthenticatedLayout>
    );
}
