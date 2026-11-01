import Modal from '@/Components/Modal';
import { Alert, BackLink, Button, Card, ChoiceGroup, EmptyState, Field, FlashMessages, Input, Page, StatusBadge, useConfirm } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PlusIcon } from '@heroicons/react/24/outline';
import { router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

// Exams. Adding or changing one is a single short form (four fields, one decision that matters: its weight), so it
// stays a form rather than a wizard; what it gains is the number that matters shown live -- what the term's weights
// add up to with this exam. The rule itself is unchanged and enforced by the server: a term's weights cannot pass
// 100% and must be exactly 100% before results can be published.

const TYPES = ['quiz', 'assignment', 'midterm', 'final', 'other'];
const round2 = (n) => Math.round(n * 100) / 100;
const numOf = (v) => { const n = Number(String(v).replace(',', '.')); return Number.isFinite(n) ? n : NaN; };

function ExamForm({ exam, terms, onDone }) {
    const { t } = useTranslation();
    const isEdit = !!exam;
    const { data, setData, post, patch, processing, errors, clearErrors, transform } = useForm({
        term_id: isEdit ? '' : terms.length === 1 ? String(terms[0].id) : '',
        name: exam?.name ?? '',
        type: exam?.type ?? '',
        weight_percent: exam ? String(exam.weight_percent) : '',
    });
    const set = (k) => (v) => { clearErrors(k); setData(k, v); };
    const term = isEdit ? terms.find((tm) => tm.id === exam.term_id) : terms.find((tm) => String(tm.id) === data.term_id);
    const weight = numOf(data.weight_percent);
    const weightOk = Number.isFinite(weight) && weight >= 0.01 && weight <= 100;
    const others = term ? round2(term.weight_total - (isEdit ? Number(exam.weight_percent) : 0)) : null;
    const total = term && weightOk ? round2(others + weight) : null;
    const ready = data.name.trim() !== '' && TYPES.includes(data.type) && weightOk && (isEdit || data.term_id !== '');

    const submit = (e) => {
        e.preventDefault();
        transform((d) => ({ ...(isEdit ? {} : { term_id: d.term_id }), name: d.name.trim(), type: d.type, weight_percent: numOf(d.weight_percent) }));
        const options = { preserveScroll: true, onSuccess: onDone };
        if (isEdit) patch(route('exams.update', exam.id), options);
        else post(route('exams.store'), options);
    };

    return (
        <form onSubmit={submit} className="space-y-6 p-6" data-testid="exam-form">
            <h2 className="text-2xl font-bold text-stone-900 dark:text-stone-100">{isEdit ? t('exam.edit_title', { name: exam.name }) : t('exam.add_title')}</h2>

            {!isEdit && (
                <div>
                    <ChoiceGroup heading={t('exam.q_term')} value={data.term_id} onChange={set('term_id')} options={terms.map((tm) => ({ value: String(tm.id), label: tm.label, hint: tm.locked ? t('exam.term_locked') : t('exam.term_total', { total: tm.weight_total }) }))} />
                    {errors.term_id && <p className="mt-2 text-sm font-medium text-red-700">{errors.term_id}</p>}
                </div>
            )}
            {!isEdit && term?.locked && <Alert tone="warning" title={t('exam.locked_title')}>{t('exam.locked_body')}</Alert>}
            {isEdit && <p className="rounded-xl bg-stone-50 p-4 text-base text-stone-800 dark:bg-stone-900/40 dark:text-stone-200">{t('exam.edit_locked', { term: exam.term })}</p>}

            <Field id="exam_name" label={t('exam.name_label')} hint={t('exam.name_hint')} error={errors.name}>
                <Input value={data.name} onChange={(e) => set('name')(e.target.value)} autoComplete="off" maxLength={255} />
            </Field>

            <div>
                <p className="mb-2 text-lg font-semibold text-stone-900 dark:text-stone-100" id="exam-type-q">{t('exam.q_type')}</p>
                <div role="radiogroup" aria-labelledby="exam-type-q" className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                    {TYPES.map((k) => (
                        <button key={k} type="button" role="radio" aria-checked={data.type === k} onClick={() => set('type')(k)}
                            className={`min-h-[56px] rounded-xl border-2 px-3 text-base font-semibold focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 ${data.type === k ? 'border-brand-600 bg-brand-600 text-white' : 'border-stone-300 bg-white text-stone-900 hover:border-brand-400 dark:border-stone-600 dark:bg-stone-800 dark:text-stone-100'}`}>
                            {t(`exam.type_${k}`)}
                        </button>
                    ))}
                </div>
                {errors.type && <p className="mt-2 text-sm font-medium text-red-700">{errors.type}</p>}
            </div>

            <Field id="exam_weight" label={t('exam.weight_label')} hint={t('exam.weight_hint')} error={errors.weight_percent ?? (data.weight_percent !== '' && !weightOk ? t('exam.err_weight') : undefined)}>
                <Input inputMode="decimal" value={data.weight_percent} onChange={(e) => set('weight_percent')(e.target.value)} autoComplete="off" />
            </Field>
            {term && weightOk && (
                <p className={`rounded-xl border-2 p-4 text-lg font-semibold ${total > 100 ? 'border-red-400 bg-red-50 text-red-900 dark:bg-red-900/20 dark:text-red-100' : total === 100 ? 'border-emerald-400 bg-emerald-50 text-emerald-900 dark:bg-emerald-900/20 dark:text-emerald-100' : 'border-brand-300 bg-brand-50 text-stone-900 dark:bg-brand-900/20 dark:text-stone-100'}`} data-testid="weight-preview">
                    {total > 100 ? t('exam.preview_over', { label: term.label, total }) : total === 100 ? t('exam.preview_complete', { label: term.label }) : t('exam.preview_more', { label: term.label, total, left: round2(100 - total) })}
                </p>
            )}

            <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                <Button variant="secondary" onClick={onDone}>{t('common.cancel')}</Button>
                <Button type="submit" variant="success" busy={processing} disabled={!ready}>{isEdit ? t('exam.save_changes') : t('exam.save_add')}</Button>
            </div>
        </form>
    );
}

export default function Index({ exams, terms }) {
    const { t } = useTranslation();
    const { auth } = usePage().props;
    const [ask, confirmDialog] = useConfirm();
    const [showCreate, setShowCreate] = useState(false);
    const [editing, setEditing] = useState(null);

    const remove = async (e) => {
        const ok = await ask({
            title: t('exam.remove_title', { name: e.name }),
            body: <p>{t('exam.remove_body')}</p>,
            confirmLabel: t('exam.remove_button', { name: e.name }),
        });
        if (ok) router.delete(route('exams.destroy', e.id), { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">{t('exam.page_title')}</h2>}>
            <Page
                title={t('exam.page_title')}
                description={t('exam.page_desc')}
                width="default"
                back={<BackLink big={auth.role === 'teacher'} href={route('dashboard')}>{t('common.back')}</BackLink>}
                actions={<Button icon={PlusIcon} onClick={() => setShowCreate(true)} disabled={terms.length === 0}>{t('exam.add_button')}</Button>}
            >
                <FlashMessages errorKeys={['exam']} />

                {terms.length === 0 && <Alert tone="warning" title={t('exam.no_terms_title')}>{t('exam.no_terms_body')}</Alert>}

                <div className="space-y-3" data-testid="terms">
                    {terms.map((tm) => (
                        <div key={tm.id} data-testid="term-row" className="flex flex-wrap items-center justify-between gap-2 rounded-2xl border-2 border-stone-200 bg-white p-4 dark:border-stone-700 dark:bg-stone-800">
                            <p className="text-lg font-semibold text-stone-900 dark:text-stone-100">{tm.label}</p>
                            <p className="flex flex-wrap items-center gap-2 text-base text-stone-800 dark:text-stone-200">
                                {t('exam.term_total', { total: tm.weight_total })}
                                {tm.complete ? <StatusBadge tone="success" label={t('exam.complete')} /> : <StatusBadge tone="warning" label={tm.weight_total < 100 ? t('exam.add_more', { left: round2(100 - tm.weight_total) }) : t('exam.over')} />}
                                {tm.locked && <StatusBadge tone="neutral" label={t('exam.locked')} />}
                            </p>
                        </div>
                    ))}
                </div>

                {exams.length === 0 ? (
                    <EmptyState title={t('exam.none_title')}>{t('exam.none_body')}</EmptyState>
                ) : (
                    <ul className="grid gap-4 sm:grid-cols-2" data-testid="exams">
                        {exams.map((e) => (
                            <li key={e.id}>
                                <Card data-testid="exam-card" className="h-full">
                                    <p className="text-xl font-bold text-stone-900 dark:text-stone-100">{e.name}</p>
                                    <p className="text-base text-stone-600 dark:text-stone-400">{e.academic_year} — {e.term}</p>
                                    <p className="mt-2 text-lg font-semibold text-stone-900 dark:text-stone-100">{t(`exam.type_${e.type}`)} · {t('exam.weight_value', { weight: e.weight_percent })}</p>
                                    <div className="mt-4 flex flex-wrap gap-2">
                                        <Button size="md" variant="secondary" onClick={() => setEditing(e)}>{t('exam.change')}</Button>
                                        <Button size="md" variant="quiet" onClick={() => remove(e)}>{t('exam.remove')}</Button>
                                    </div>
                                </Card>
                            </li>
                        ))}
                    </ul>
                )}
            </Page>

            <Modal show={showCreate} onClose={() => setShowCreate(false)}>
                {showCreate && <ExamForm terms={terms} onDone={() => setShowCreate(false)} />}
            </Modal>
            <Modal show={!!editing} onClose={() => setEditing(null)}>
                {editing && <ExamForm key={editing.id} exam={editing} terms={terms} onDone={() => setEditing(null)} />}
            </Modal>
            {confirmDialog}
        </AuthenticatedLayout>
    );
}
