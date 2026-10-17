import { Button, Card, EmptyState, FlashMessages, Page, Select, StatusBadge } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { ClipboardDocumentListIcon, PlusIcon } from '@heroicons/react/24/outline';
import { Link, router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

const money = (n) => Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const STATUS_TONE = { draft: 'neutral', posted: 'success' };

export default function Index({ entries, status, canManage }) {
    const { t } = useTranslation();

    const filter = (value) => router.get(route('journal-entries.index'), value ? { status: value } : {}, { preserveState: true, replace: true });

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">{t('journal_entry.list_title')}</h2>}>
            <Page
                title={t('journal_entry.list_title')}
                description={t('journal_entry.list_desc')}
                actions={canManage && <Button as={Link} href={route('journal-entries.create')} icon={PlusIcon}>{t('journal_entry.new_button')}</Button>}
            >
                <FlashMessages />
                <Select value={status} onChange={(e) => filter(e.target.value)} className="max-w-xs">
                    <option value="">{t('journal_entry.all_statuses')}</option>
                    <option value="draft">{t('journal_entry.status_draft')}</option>
                    <option value="posted">{t('journal_entry.status_posted')}</option>
                </Select>

                {entries.data.length === 0 ? (
                    <Card><EmptyState icon={ClipboardDocumentListIcon} title={t('journal_entry.no_entries_title')}>{t('journal_entry.no_entries_body')}</EmptyState></Card>
                ) : (
                    <ul className="grid gap-4 lg:grid-cols-2" data-testid="je-list">
                        {entries.data.map((e) => (
                            <li key={e.id} data-testid="je-row" className="rounded-2xl border-2 border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-800">
                                <div className="flex flex-wrap items-start justify-between gap-2">
                                    <div className="min-w-0">
                                        <Link href={route('journal-entries.show', e.id)} className="text-lg font-semibold text-gray-900 hover:underline dark:text-gray-100">{e.entry_number}</Link>
                                        <p className="text-sm text-gray-600 dark:text-gray-400">{e.journal} &middot; {e.entry_date}</p>
                                    </div>
                                    <StatusBadge tone={e.is_reversed ? 'danger' : STATUS_TONE[e.status]} label={e.is_reversed ? t('journal_entry.status_reversed') : t(`journal_entry.status_${e.status}`)} />
                                </div>
                                {e.description && <p className="mt-2 text-sm text-gray-700 dark:text-gray-300">{e.description}</p>}
                                <p className="mt-2 text-base font-semibold text-gray-900 dark:text-gray-100">{money(e.total)} ETB</p>
                            </li>
                        ))}
                    </ul>
                )}

                {(entries.prev_page_url || entries.next_page_url) && (
                    <div className="flex gap-3">
                        {entries.prev_page_url && <Button as={Link} href={entries.prev_page_url} variant="secondary" fullWidth>{t('inv.newer')}</Button>}
                        {entries.next_page_url && <Button as={Link} href={entries.next_page_url} variant="secondary" fullWidth>{t('inv.older')}</Button>}
                    </div>
                )}
            </Page>
        </AuthenticatedLayout>
    );
}
