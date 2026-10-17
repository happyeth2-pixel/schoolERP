import { BackLink, Button, Field, FlashMessages, Input, Page, Select, TextArea } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PlusIcon, TrashIcon } from '@heroicons/react/24/outline';
import { router, useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

const emptyLine = () => ({ account_id: '', debit: '', credit: '', description: '' });
const money = (n) => Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const today = () => new Date().toISOString().slice(0, 10);

export default function Create({ accounts, journals, entry }) {
    const { t } = useTranslation();
    const editing = entry !== null;
    const { data, setData, post, patch, processing, errors } = useForm({
        journal_id: entry ? String(entry.journal_id) : (journals[0]?.id ? String(journals[0].id) : ''),
        entry_date: entry?.entry_date ?? today(),
        reference: entry?.reference ?? '',
        description: entry?.description ?? '',
        lines: entry ? entry.lines.map((l) => ({ ...l, account_id: String(l.account_id) })) : [emptyLine(), emptyLine()],
    });

    const setLine = (index, patch) => setData('lines', data.lines.map((l, i) => (i === index ? { ...l, ...patch } : l)));
    const addLine = () => setData('lines', [...data.lines, emptyLine()]);
    const removeLine = (index) => setData('lines', data.lines.filter((_, i) => i !== index));

    const totalDebit = data.lines.reduce((sum, l) => sum + Number(l.debit || 0), 0);
    const totalCredit = data.lines.reduce((sum, l) => sum + Number(l.credit || 0), 0);
    const balanced = totalDebit > 0 && Math.abs(totalDebit - totalCredit) < 0.005;
    const linesValid = data.lines.every((l) => l.account_id && (Number(l.debit || 0) > 0) !== (Number(l.credit || 0) > 0));

    const submit = (e) => {
        e.preventDefault();
        if (editing) {
            patch(route('journal-entries.update', entry.id), { onSuccess: () => router.visit(route('journal-entries.show', entry.id)) });
        } else {
            post(route('journal-entries.store'));
        }
    };

    const title = editing ? t('journal_entry.edit_title', { number: entry.entry_number }) : t('journal_entry.new_title');
    const backHref = editing ? route('journal-entries.show', entry.id) : route('journal-entries.index');
    const backLabel = editing ? entry.entry_number : t('journal_entry.back_to_list');

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">{title}</h2>}>
            <Page title={title} back={<BackLink href={backHref}>{backLabel}</BackLink>}>
                <FlashMessages errorKeys={['lines']} />
                <form onSubmit={submit} className="space-y-6">
                    <Field id="je_journal" label={t('journal_entry.journal')} error={errors.journal_id}>
                        <Select value={data.journal_id} onChange={(e) => setData('journal_id', e.target.value)}>
                            {journals.map((j) => <option key={j.id} value={j.id}>{j.code} {j.name}</option>)}
                        </Select>
                    </Field>
                    <Field id="je_date" label={t('journal_entry.entry_date')} error={errors.entry_date}>
                        <Input type="date" value={data.entry_date} onChange={(e) => setData('entry_date', e.target.value)} />
                    </Field>
                    <Field id="je_reference" label={t('journal_entry.reference')} optional error={errors.reference}>
                        <Input value={data.reference} onChange={(e) => setData('reference', e.target.value)} maxLength={255} autoComplete="off" />
                    </Field>
                    <Field id="je_description" label={t('journal_entry.description')} optional error={errors.description}>
                        <TextArea id="je_description" value={data.description} onChange={(e) => setData('description', e.target.value)} />
                    </Field>

                    <div className="space-y-4 rounded-xl border-2 border-gray-200 p-4 dark:border-gray-700">
                        <p className="text-base font-semibold text-gray-900 dark:text-gray-100">{t('journal_entry.lines_title')}</p>
                        {data.lines.map((line, index) => (
                            <div key={index} className="space-y-2 border-b border-gray-200 pb-4 last:border-0 last:pb-0 dark:border-gray-700" data-testid="je-line">
                                <div className="flex items-start justify-between gap-2">
                                    <p className="text-sm font-medium text-gray-800 dark:text-gray-200">{t('journal_entry.line_n', { n: index + 1 })}</p>
                                    {data.lines.length > 2 && (
                                        <Button type="button" variant="quiet" size="md" icon={TrashIcon} onClick={() => removeLine(index)}>{t('journal_entry.remove_line')}</Button>
                                    )}
                                </div>
                                <Field id={`je_account_${index}`} label={t('journal_entry.account')} error={errors[`lines.${index}.account_id`]}>
                                    <Select value={line.account_id} onChange={(e) => setLine(index, { account_id: e.target.value })}>
                                        <option value="">--</option>
                                        {accounts.map((a) => <option key={a.id} value={a.id}>{a.code} {a.name}</option>)}
                                    </Select>
                                </Field>
                                <div className="grid grid-cols-2 gap-2">
                                    <Field id={`je_debit_${index}`} label={t('journal_entry.debit')} error={errors[`lines.${index}.debit`]}>
                                        <Input inputMode="decimal" value={line.debit} onChange={(e) => setLine(index, { debit: e.target.value, credit: '' })} autoComplete="off" />
                                    </Field>
                                    <Field id={`je_credit_${index}`} label={t('journal_entry.credit')} error={errors[`lines.${index}.credit`]}>
                                        <Input inputMode="decimal" value={line.credit} onChange={(e) => setLine(index, { credit: e.target.value, debit: '' })} autoComplete="off" />
                                    </Field>
                                </div>
                            </div>
                        ))}
                        <Button type="button" variant="secondary" icon={PlusIcon} onClick={addLine}>{t('journal_entry.add_line')}</Button>

                        <div className="flex items-center justify-between border-t-2 border-gray-200 pt-4 dark:border-gray-700">
                            <p className="text-base text-gray-800 dark:text-gray-200">{t('journal_entry.totals', { debit: money(totalDebit), credit: money(totalCredit) })}</p>
                            {!balanced && <p className="text-sm font-semibold text-red-600 dark:text-red-400">{t('journal_entry.not_balanced')}</p>}
                        </div>
                    </div>

                    <Button type="submit" fullWidth busy={processing} disabled={!data.journal_id || !linesValid}>{editing ? t('journal_entry.save_changes_button') : t('journal_entry.save_draft_button')}</Button>
                    {!balanced && <p className="text-center text-sm text-gray-600 dark:text-gray-400">{t('journal_entry.draft_can_be_unbalanced')}</p>}
                </form>
            </Page>
        </AuthenticatedLayout>
    );
}
