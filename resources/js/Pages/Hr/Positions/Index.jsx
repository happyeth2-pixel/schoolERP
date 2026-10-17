import PositionDialog from '@/Components/PositionDialog';
import { Button, Card, EmptyState, FlashMessages, Page, useConfirm } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { IdentificationIcon, PlusIcon } from '@heroicons/react/24/outline';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

export default function Index({ positions, departments, canManage }) {
    const { t } = useTranslation();
    const [dialog, setDialog] = useState(null); // null | 'new' | a position object
    const [ask, confirmDialog] = useConfirm();

    const remove = async (position) => {
        if (await ask({
            title: t('position.remove_title', { name: position.title }),
            body: t('position.remove_body'),
            confirmLabel: t('position.remove_confirm'),
            tone: 'danger',
        })) {
            router.delete(route('positions.destroy', position.id), { preserveScroll: true });
        }
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">{t('position.list_title')}</h2>}>
            <Page
                title={t('position.list_title')}
                description={t('position.list_desc')}
                actions={canManage && <Button icon={PlusIcon} onClick={() => setDialog('new')}>{t('position.add_button')}</Button>}
            >
                <FlashMessages errorKeys={['title']} />

                {positions.length === 0 ? (
                    <Card><EmptyState icon={IdentificationIcon} title={t('position.empty_title')}>{t('position.empty_body')}</EmptyState></Card>
                ) : (
                    <Card>
                        <ul className="divide-y divide-gray-200 dark:divide-gray-700" data-testid="position-list">
                            {positions.map((p) => (
                                <li key={p.id} className="flex flex-wrap items-center justify-between gap-2 py-3" data-testid="position-row">
                                    <div>
                                        <span className="text-base font-semibold text-gray-900 dark:text-gray-100">{p.title}</span>
                                        {p.department && <span className="ml-2 text-sm text-gray-600 dark:text-gray-400">{p.department}</span>}
                                        <p className="text-sm text-gray-600 dark:text-gray-400">{t('position.employees_count', { count: p.employees_count })}</p>
                                    </div>
                                    {canManage && (
                                        <div className="flex items-center gap-3">
                                            <Button size="sm" variant="quiet" onClick={() => setDialog(p)}>{t('position.edit_button')}</Button>
                                            <Button size="sm" variant="quiet" onClick={() => remove(p)}>{t('position.remove_button')}</Button>
                                        </div>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </Card>
                )}
            </Page>
            <PositionDialog
                key={dialog === 'new' || dialog === null ? 'new' : `edit-${dialog.id}`}
                open={dialog !== null}
                departments={departments}
                position={dialog === 'new' ? null : dialog}
                onClose={() => setDialog(null)}
            />
            {confirmDialog}
        </AuthenticatedLayout>
    );
}
