import { BackLink, Button, Card, EmptyState, FlashMessages, Page, StatusBadge } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PlusIcon, UserGroupIcon } from '@heroicons/react/24/outline';
import { Link, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

const STATUS_TONE = { active: 'success', on_leave: 'info', suspended: 'warning', terminated: 'danger' };

export default function Index({ employees, canManage }) {
    const { t } = useTranslation();
    const { auth } = usePage().props;

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">{t('employee.list_title')}</h2>}>
            <Page
                title={t('employee.list_title')}
                description={t('employee.list_desc')}
                back={<BackLink big={auth.role === 'teacher'} href={route('dashboard')}>{t('common.back')}</BackLink>}
                actions={canManage && <Button as={Link} href={route('employees.create')} icon={PlusIcon}>{t('employee.new_button')}</Button>}
            >
                <FlashMessages />

                {employees.length === 0 ? (
                    <Card><EmptyState icon={UserGroupIcon} title={t('employee.empty_title')}>{t('employee.empty_body')}</EmptyState></Card>
                ) : (
                    <ul className="grid gap-4 lg:grid-cols-2" data-testid="employee-list">
                        {employees.map((e) => (
                            <li key={e.id} data-testid="employee-row" className="flex items-center gap-4 rounded-2xl border-2 border-stone-200 bg-white p-5 dark:border-stone-700 dark:bg-stone-800">
                                {e.photo_url ? (
                                    <img src={e.photo_url} alt="" className="h-14 w-14 shrink-0 rounded-full object-cover" />
                                ) : (
                                    <div className="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-stone-100 text-lg font-semibold text-stone-500 dark:bg-stone-700 dark:text-stone-300">
                                        {e.full_name.charAt(0).toUpperCase()}
                                    </div>
                                )}
                                <div className="min-w-0 flex-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <Link href={route('employees.show', e.id)} className="text-lg font-semibold text-stone-900 hover:underline dark:text-stone-100">{e.full_name}</Link>
                                        <StatusBadge tone={STATUS_TONE[e.employment_status]} label={t(`employee.status_${e.employment_status}`)} />
                                    </div>
                                    <p className="text-sm text-stone-600 dark:text-stone-400">{e.employee_number}{e.position ? ` · ${e.position}` : ''}{e.department ? ` · ${e.department}` : ''}</p>
                                    {!e.has_account && <p className="text-sm text-amber-700 dark:text-amber-400">{t('employee.no_account_badge')}</p>}
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </Page>
        </AuthenticatedLayout>
    );
}
