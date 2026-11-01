import DepartmentDialog from '@/Components/DepartmentDialog';
import { BackLink, Button, Card, EmptyState, FlashMessages, Page, useConfirm } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { BuildingOffice2Icon, PlusIcon } from '@heroicons/react/24/outline';
import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

export default function Index({ departments, branches, employees, canManage }) {
    const { t } = useTranslation();
    const { auth } = usePage().props;
    const [dialog, setDialog] = useState(null); // null | 'new' | a department object
    const [ask, confirmDialog] = useConfirm();

    const remove = async (department) => {
        if (await ask({
            title: t('department.remove_title', { name: department.name }),
            body: t('department.remove_body'),
            confirmLabel: t('department.remove_confirm'),
            tone: 'danger',
        })) {
            router.delete(route('departments.destroy', department.id), { preserveScroll: true });
        }
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">{t('department.list_title')}</h2>}>
            <Page
                title={t('department.list_title')}
                description={t('department.list_desc')}
                back={<BackLink big={auth.role === 'teacher'} href={route('dashboard')}>{t('common.back')}</BackLink>}
                actions={canManage && <Button icon={PlusIcon} onClick={() => setDialog('new')}>{t('department.add_button')}</Button>}
            >
                <FlashMessages errorKeys={['name']} />

                {departments.length === 0 ? (
                    <Card><EmptyState icon={BuildingOffice2Icon} title={t('department.empty_title')}>{t('department.empty_body')}</EmptyState></Card>
                ) : (
                    <Card>
                        <ul className="divide-y divide-stone-200 dark:divide-stone-700" data-testid="department-list">
                            {departments.map((d) => (
                                <li key={d.id} className="flex flex-wrap items-center justify-between gap-2 py-3" data-testid="department-row">
                                    <div>
                                        <span className="text-base font-semibold text-stone-900 dark:text-stone-100">{d.name}</span>
                                        {d.branch && <span className="ml-2 text-sm text-stone-600 dark:text-stone-400">{d.branch}</span>}
                                        <p className="text-sm text-stone-600 dark:text-stone-400">
                                            {t('department.employees_count', { count: d.employees_count })}
                                            {d.manager && ` · ${t('department.managed_by', { name: d.manager })}`}
                                        </p>
                                    </div>
                                    {canManage && (
                                        <div className="flex items-center gap-3">
                                            <Button size="sm" variant="quiet" onClick={() => setDialog(d)}>{t('department.edit_button')}</Button>
                                            <Button size="sm" variant="quiet" onClick={() => remove(d)}>{t('department.remove_button')}</Button>
                                        </div>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </Card>
                )}
            </Page>
            <DepartmentDialog
                key={dialog === 'new' || dialog === null ? 'new' : `edit-${dialog.id}`}
                open={dialog !== null}
                branches={branches}
                employees={employees}
                department={dialog === 'new' ? null : dialog}
                onClose={() => setDialog(null)}
            />
            {confirmDialog}
        </AuthenticatedLayout>
    );
}
