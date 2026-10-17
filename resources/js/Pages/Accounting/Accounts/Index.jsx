import AccountDialog from '@/Components/AccountDialog';
import { Button, Card, EmptyState, FlashMessages, Page, StatusBadge } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { BookOpenIcon, PlusIcon } from '@heroicons/react/24/outline';
import { router } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';

function AccountRow({ account, depth, byParent, canManage, onEdit }) {
    const { t } = useTranslation();
    const children = byParent[account.id] ?? [];

    return (
        <>
            <li className="flex flex-wrap items-center justify-between gap-2 py-3" data-testid="account-row" style={{ paddingLeft: `${depth * 1.5}rem` }}>
                <div className="min-w-0">
                    <span className={`text-base ${account.is_header ? 'font-bold text-gray-900 dark:text-gray-100' : 'font-medium text-gray-800 dark:text-gray-200'}`}>
                        {account.code} {account.name}
                    </span>
                    {account.is_system && <span className="ml-2 text-xs text-gray-500">{t('account.system_label')}</span>}
                    {!account.is_active && <StatusBadge tone="neutral" label={t('account.inactive_label')} className="ml-2" />}
                </div>
                {canManage && !account.is_header && (
                    <Button size="sm" variant="quiet" onClick={() => onEdit(account)}>{t('common.edit')}</Button>
                )}
                {canManage && account.is_header && (
                    <Button size="sm" variant="quiet" onClick={() => onEdit(account)}>{t('common.edit')}</Button>
                )}
            </li>
            {children.map((child) => (
                <AccountRow key={child.id} account={child} depth={depth + 1} byParent={byParent} canManage={canManage} onEdit={onEdit} />
            ))}
        </>
    );
}

export default function Index({ accounts, installed, canManage, types }) {
    const { t } = useTranslation();
    const [dialogAccount, setDialogAccount] = useState(undefined); // undefined = closed, null = adding, object = editing

    const byParent = useMemo(() => {
        const map = {};
        accounts.forEach((a) => {
            const key = a.parent_id ?? 'root';
            (map[key] ??= []).push(a);
        });
        return map;
    }, [accounts]);

    const roots = byParent.root ?? [];

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">{t('account.list_title')}</h2>}>
            <Page
                title={t('account.list_title')}
                description={t('account.list_desc')}
                actions={canManage && <Button icon={PlusIcon} onClick={() => setDialogAccount(null)}>{t('account.add_button')}</Button>}
            >
                <FlashMessages />

                {!installed && canManage && (
                    <Card>
                        <p className="text-base text-gray-800 dark:text-gray-200">{t('account.install_prompt')}</p>
                        <Button className="mt-3" onClick={() => router.post(route('accounts.install-template'))}>{t('account.install_button')}</Button>
                    </Card>
                )}

                {accounts.length === 0 ? (
                    <Card><EmptyState icon={BookOpenIcon} title={t('account.empty_title')}>{t('account.empty_body')}</EmptyState></Card>
                ) : (
                    <Card>
                        <ul className="divide-y divide-gray-200 dark:divide-gray-700" data-testid="account-list">
                            {roots.map((account) => (
                                <AccountRow key={account.id} account={account} depth={0} byParent={byParent} canManage={canManage} onEdit={setDialogAccount} />
                            ))}
                        </ul>
                    </Card>
                )}
            </Page>

            <AccountDialog
                key={dialogAccount === undefined ? 'closed' : dialogAccount === null ? 'new' : `edit-${dialogAccount.id}`}
                open={dialogAccount !== undefined}
                account={dialogAccount ?? null}
                accounts={accounts}
                types={types}
                onClose={() => setDialogAccount(undefined)}
            />
        </AuthenticatedLayout>
    );
}
