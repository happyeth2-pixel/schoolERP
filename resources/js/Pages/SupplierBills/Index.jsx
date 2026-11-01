import { BackLink, Button, Card, EmptyState, FlashMessages, Page } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { DocumentTextIcon, PlusIcon } from '@heroicons/react/24/outline';
import { Link, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

const money = (n) => Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

export default function Index({ bills }) {
    const { t } = useTranslation();
    const { auth } = usePage().props;

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">{t('supplier_bill.list_title')}</h2>}>
            <Page
                title={t('supplier_bill.list_title')}
                description={t('supplier_bill.list_desc')}
                back={<BackLink big={auth.role === 'teacher'} href={route('dashboard')}>{t('common.back')}</BackLink>}
                actions={<Button as={Link} href={route('supplier-bills.create')} icon={PlusIcon}>{t('supplier_bill.new_button')}</Button>}
            >
                <FlashMessages />

                {bills.data.length === 0 ? (
                    <Card><EmptyState icon={DocumentTextIcon} title={t('supplier_bill.empty_title')}>{t('supplier_bill.empty_body')}</EmptyState></Card>
                ) : (
                    <ul className="grid gap-4 lg:grid-cols-2" data-testid="bill-list">
                        {bills.data.map((b) => (
                            <li key={b.id} data-testid="bill-row" className="rounded-2xl border-2 border-stone-200 bg-white p-5 dark:border-stone-700 dark:bg-stone-800">
                                <div className="flex flex-wrap items-start justify-between gap-2">
                                    <div className="min-w-0">
                                        <Link href={route('supplier-bills.show', b.id)} className="text-lg font-semibold text-stone-900 hover:underline dark:text-stone-100">{b.bill_number}</Link>
                                        <p className="text-sm text-stone-600 dark:text-stone-400">{b.supplier}</p>
                                    </div>
                                    <span className="text-sm text-stone-600 dark:text-stone-400">{b.bill_date}</span>
                                </div>
                                <p className="mt-3 text-base font-semibold text-stone-900 dark:text-stone-100">{money(b.total)} ETB</p>
                                <p className="text-sm text-stone-600 dark:text-stone-400">
                                    {b.balance > 0 ? t('supplier_bill.balance_x', { amount: money(b.balance) }) : t('supplier_bill.fully_paid')}
                                </p>
                            </li>
                        ))}
                    </ul>
                )}

                {(bills.prev_page_url || bills.next_page_url) && (
                    <div className="flex gap-3">
                        {bills.prev_page_url && <Button as={Link} href={bills.prev_page_url} variant="secondary" fullWidth>{t('inv.newer')}</Button>}
                        {bills.next_page_url && <Button as={Link} href={bills.next_page_url} variant="secondary" fullWidth>{t('inv.older')}</Button>}
                    </div>
                )}
            </Page>
        </AuthenticatedLayout>
    );
}
