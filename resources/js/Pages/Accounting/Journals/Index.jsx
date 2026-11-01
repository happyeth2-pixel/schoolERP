import JournalDialog from '@/Components/JournalDialog';
import { BackLink, Button, Card, FlashMessages, Page, StatusBadge } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PlusIcon } from '@heroicons/react/24/outline';
import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

export default function Index({ journals, canManage, types }) {
    const { t } = useTranslation();
    const { auth } = usePage().props;
    const isTeacher = auth.role === 'teacher';
    const [adding, setAdding] = useState(false);

    const toggleActive = (journal) => {
        router.patch(route('journals.update', journal.id), { name: journal.name, is_active: !journal.is_active }, { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">{t('journal.list_title')}</h2>}>
            <Page
                title={t('journal.list_title')}
                description={t('journal.list_desc')}
                back={<BackLink big={isTeacher} href={route('dashboard')}>{t('common.back')}</BackLink>}
                actions={canManage && <Button icon={PlusIcon} onClick={() => setAdding(true)}>{t('journal.add_button')}</Button>}
            >
                <FlashMessages />
                <Card>
                    <ul className="divide-y divide-stone-200 dark:divide-stone-700" data-testid="journal-list">
                        {journals.map((j) => (
                            <li key={j.id} className="flex flex-wrap items-center justify-between gap-2 py-3" data-testid="journal-row">
                                <div>
                                    <span className="text-base font-semibold text-stone-900 dark:text-stone-100">{j.code} {j.name}</span>
                                    <span className="ml-2 text-sm text-stone-600 dark:text-stone-400">{t(`journal.type_${j.type}`)}</span>
                                </div>
                                <div className="flex items-center gap-3">
                                    {!j.is_active && <StatusBadge tone="neutral" label={t('journal.inactive_label')} />}
                                    {canManage && (
                                        <Button size="sm" variant="quiet" onClick={() => toggleActive(j)}>
                                            {j.is_active ? t('journal.deactivate_button') : t('journal.activate_button')}
                                        </Button>
                                    )}
                                </div>
                            </li>
                        ))}
                    </ul>
                </Card>
            </Page>
            <JournalDialog open={adding} types={types} onClose={() => setAdding(false)} />
        </AuthenticatedLayout>
    );
}
