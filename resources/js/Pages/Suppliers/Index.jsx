import SearchInput from '@/Components/SearchInput';
import { Button, Card, EmptyState, FlashMessages, Page } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { BuildingOffice2Icon, PlusIcon } from '@heroicons/react/24/outline';
import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

export default function Index({ suppliers, search, canManage }) {
    const { t } = useTranslation();
    const [q, setQ] = useState(search);

    const runSearch = (value) => {
        setQ(value);
        router.get(route('suppliers.index'), value ? { search: value } : {}, { preserveState: true, replace: true });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">{t('supplier.list_title')}</h2>}>
            <Page
                title={t('supplier.list_title')}
                description={t('supplier.list_desc')}
                actions={canManage && <Button as={Link} href={route('suppliers.create')} icon={PlusIcon}>{t('supplier.add_button')}</Button>}
            >
                <FlashMessages />
                <SearchInput value={q} onChange={runSearch} placeholder={t('supplier.search_placeholder')} />

                {suppliers.data.length === 0 ? (
                    <Card><EmptyState icon={BuildingOffice2Icon} title={t('supplier.no_suppliers_title')}>{t('supplier.no_suppliers_body')}</EmptyState></Card>
                ) : (
                    <ul className="grid gap-4 lg:grid-cols-2" data-testid="supplier-list">
                        {suppliers.data.map((s) => (
                            <li key={s.id} data-testid="supplier-row" className="rounded-2xl border-2 border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-800">
                                <Link href={route('suppliers.show', s.id)} className="text-lg font-semibold text-gray-900 hover:underline dark:text-gray-100">{s.name}</Link>
                                <p className="text-sm text-gray-600 dark:text-gray-400">{s.code}{s.contact_person ? ` · ${s.contact_person}` : ''}{s.phone ? ` · ${s.phone}` : ''}</p>
                            </li>
                        ))}
                    </ul>
                )}

                {(suppliers.prev_page_url || suppliers.next_page_url) && (
                    <div className="flex gap-3">
                        {suppliers.prev_page_url && <Button as={Link} href={suppliers.prev_page_url} variant="secondary" fullWidth>{t('inv.newer')}</Button>}
                        {suppliers.next_page_url && <Button as={Link} href={suppliers.next_page_url} variant="secondary" fullWidth>{t('inv.older')}</Button>}
                    </div>
                )}
            </Page>
        </AuthenticatedLayout>
    );
}
