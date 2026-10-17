import { Alert, BackLink, Button, Card, Field, FlashMessages, Page, SectionHeader, StatusBadge, TextArea, useConfirm } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

const money = (n) => Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const STATUS_TONE = { draft: 'neutral', posted: 'success' };

function ReverseDialog({ open, entry, onClose }) {
    const { t } = useTranslation();
    const { data, setData, post, processing, reset } = useForm({ reason: '' });

    const submit = (e) => {
        e.preventDefault();
        post(route('journal-entries.reverse', entry.id), { onSuccess: () => { reset(); onClose(); } });
    };

    if (!open) return null;

    return (
        <Card>
            <form onSubmit={submit} className="space-y-4" data-testid="reverse-form">
                <SectionHeader as="h3" title={t('journal_entry.reverse_title', { number: entry.entry_number })} />
                <Field id="reverse_reason" label={t('journal_entry.reverse_reason')} optional>
                    <TextArea id="reverse_reason" value={data.reason} onChange={(e) => setData('reason', e.target.value)} />
                </Field>
                <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                    <Button type="button" variant="secondary" onClick={onClose}>{t('common.cancel')}</Button>
                    <Button type="submit" variant="danger" busy={processing}>{t('journal_entry.reverse_button')}</Button>
                </div>
            </form>
        </Card>
    );
}

export default function Show({ entry, canManage }) {
    const { t } = useTranslation();
    const [reversing, setReversing] = useState(false);
    const [ask, confirmDialog] = useConfirm();

    const postEntry = () => router.post(route('journal-entries.post', entry.id));

    const remove = async () => {
        const confirmed = await ask({
            title: t('journal_entry.remove_title'),
            body: t('journal_entry.remove_body', { number: entry.entry_number }),
            confirmLabel: t('journal_entry.remove_button'),
        });
        if (confirmed) router.delete(route('journal-entries.destroy', entry.id));
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">{entry.entry_number}</h2>}>
            <Page title={entry.entry_number} back={<BackLink href={route('journal-entries.index')}>{t('journal_entry.back_to_list')}</BackLink>}>
                <FlashMessages errorKeys={['status', 'lines', 'accounting_period_id']} />
                <Card>
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p className="text-lg font-semibold text-gray-900 dark:text-gray-100">{entry.journal}</p>
                            <p className="text-sm text-gray-600 dark:text-gray-400">{t('journal_entry.dated_x', { date: entry.entry_date })} &middot; {entry.period.name}</p>
                        </div>
                        <StatusBadge tone={STATUS_TONE[entry.status]} label={t(`journal_entry.status_${entry.status}`)} />
                    </div>
                    {entry.description && <p className="mt-3 text-base text-gray-700 dark:text-gray-300">{entry.description}</p>}
                    {entry.reference && <p className="text-sm text-gray-600 dark:text-gray-400">{t('journal_entry.reference_x', { reference: entry.reference })}</p>}
                    <p className="mt-3 text-xl font-bold text-gray-900 dark:text-gray-100">{money(entry.total_debit)} ETB</p>
                    {entry.reverses && (
                        <Alert tone="info" title={t('journal_entry.reverses_x', { number: entry.reverses.entry_number })} className="mt-4">
                            <Link href={route('journal-entries.show', entry.reverses.id)} className="text-indigo-600 hover:underline dark:text-indigo-400">{entry.reverses.entry_number}</Link>
                        </Alert>
                    )}
                    {entry.reversed_by && (
                        <Alert tone="danger" title={t('journal_entry.reversed_by_x', { number: entry.reversed_by.entry_number })} className="mt-4">
                            <Link href={route('journal-entries.show', entry.reversed_by.id)} className="text-indigo-600 hover:underline dark:text-indigo-400">{entry.reversed_by.entry_number}</Link>
                        </Alert>
                    )}
                    <p className="mt-3 text-xs text-gray-500">{t('journal_entry.created_by_x', { name: entry.created_by })}{entry.posted_by && ` · ${t('journal_entry.posted_by_x', { name: entry.posted_by })}`}</p>
                </Card>

                <Card>
                    <SectionHeader as="h3" title={t('journal_entry.lines_title')} />
                    <div className="overflow-x-auto">
                    <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700" data-testid="je-lines">
                        <thead>
                            <tr>
                                <th className="px-2 py-2 text-left text-xs font-medium uppercase text-gray-500">{t('journal_entry.account')}</th>
                                <th className="px-2 py-2 text-right text-xs font-medium uppercase text-gray-500">{t('journal_entry.debit')}</th>
                                <th className="px-2 py-2 text-right text-xs font-medium uppercase text-gray-500">{t('journal_entry.credit')}</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
                            {entry.lines.map((l) => (
                                <tr key={l.id} data-testid="je-line-row">
                                    <td className="px-2 py-2 text-sm text-gray-900 dark:text-gray-100">{l.account}{l.description && <span className="block text-xs text-gray-500">{l.description}</span>}</td>
                                    <td className="px-2 py-2 text-right text-sm text-gray-700 dark:text-gray-300">{Number(l.debit) > 0 ? money(l.debit) : ''}</td>
                                    <td className="px-2 py-2 text-right text-sm text-gray-700 dark:text-gray-300">{Number(l.credit) > 0 ? money(l.credit) : ''}</td>
                                </tr>
                            ))}
                        </tbody>
                        <tfoot>
                            <tr className="border-t-2 border-gray-300 font-bold dark:border-gray-600">
                                <td className="px-2 py-2 text-sm text-gray-900 dark:text-gray-100">{t('journal_entry.total')}</td>
                                <td className="px-2 py-2 text-right text-sm text-gray-900 dark:text-gray-100">{money(entry.total_debit)}</td>
                                <td className="px-2 py-2 text-right text-sm text-gray-900 dark:text-gray-100">{money(entry.total_credit)}</td>
                            </tr>
                        </tfoot>
                    </table>
                    </div>
                </Card>

                {canManage && entry.status === 'draft' && (
                    <div className="flex flex-col gap-3 sm:flex-row">
                        <Button variant="success" fullWidth onClick={postEntry}>{t('journal_entry.post_button')}</Button>
                        <Button as={Link} href={route('journal-entries.edit', entry.id)} variant="secondary" fullWidth>{t('journal_entry.edit_lines_button')}</Button>
                        <Button variant="secondary" fullWidth onClick={remove}>{t('journal_entry.remove_button')}</Button>
                    </div>
                )}
                {canManage && entry.status === 'posted' && !entry.reversed_by && (
                    <Button variant="danger" fullWidth onClick={() => setReversing(true)}>{t('journal_entry.reverse_button')}</Button>
                )}
                <ReverseDialog open={reversing} entry={entry} onClose={() => setReversing(false)} />
            </Page>
            {confirmDialog}
        </AuthenticatedLayout>
    );
}
