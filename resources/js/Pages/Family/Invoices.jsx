import { BackLink, Button, Card, EmptyState, Page, StatusBadge } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { BanknotesIcon, PrinterIcon } from '@heroicons/react/24/outline';
import { Link, router, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

// Every bill across every one of this parent's children, as one simple timeline per child -- the
// most recent period first, each one marked paid / partly paid / unpaid / waived -- plus the lifetime
// total paid and a printable statement. A family may pay any bill, in any order, whenever they wish
// (InvoicePolicy::pay already allows that); the actual pay/view screen is the existing Invoices/Show.

const STATUSES = ['unpaid', 'partially_paid', 'paid', 'waived'];
const money = (n) => Number(n).toLocaleString(undefined, { maximumFractionDigits: 2 });

export default function Invoices({ invoices, children, filters }) {
    const { t } = useTranslation();
    const { locale } = usePage().props;
    const current = filters?.status ?? null;
    const setFilter = (status) => router.get(route('family.invoices'), status ? { status } : {}, { preserveState: true, preserveScroll: true });

    const filterOptions = [[null, t('invoices.filter_all')], ...STATUSES.map((s) => [s, t(`status.${s}`)])];
    const day = (iso) => new Date(`${iso}T00:00:00`).toLocaleDateString(locale ?? undefined, { day: 'numeric', month: 'short', year: 'numeric' });

    const byChild = children.map((c) => ({ child: c, bills: invoices.filter((i) => i.student_id === c.id) }));

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">{t('invoices.my_title')}</h2>}>
            <Page title={t('invoices.my_title')} width="default" back={<BackLink big href={route('dashboard')}>{t('parent.back_home')}</BackLink>}>
                {children.length === 0 ? (
                    <Card><EmptyState icon={BanknotesIcon} title={t('parent.no_child_title')}>{t('parent.no_child_body')}</EmptyState></Card>
                ) : (
                    <div className="space-y-6">
                        <div className="flex flex-wrap gap-2 text-sm" data-testid="my-invoices-filters">
                            {filterOptions.map(([value, label]) => (
                                <button
                                    key={label}
                                    type="button"
                                    onClick={() => setFilter(value)}
                                    className={`min-h-[36px] rounded-full px-3 py-1 font-medium ${current === value ? 'bg-brand-600 text-white' : 'bg-stone-100 text-stone-600 dark:bg-stone-700 dark:text-stone-300'}`}
                                >
                                    {label}
                                </button>
                            ))}
                        </div>

                        {invoices.length === 0 ? (
                            <Card><EmptyState icon={BanknotesIcon} title={t('invoices.my_none_title')}>{t('invoices.my_none_body')}</EmptyState></Card>
                        ) : (
                            byChild.filter((g) => g.bills.length > 0).map(({ child, bills }) => (
                                <Card key={child.id} data-testid="my-child-statement">
                                    <div className="flex flex-wrap items-center justify-between gap-3 border-b border-stone-200 pb-4 dark:border-stone-700">
                                        <div>
                                            <p className="text-xl font-bold text-stone-900 dark:text-stone-100">{child.name}</p>
                                            <p className="text-lg font-semibold text-emerald-700 dark:text-emerald-400" data-testid="child-total-paid">
                                                {t('invoices.my_total_paid_lifetime', { amount: money(child.total_paid) })}
                                            </p>
                                        </div>
                                        <Button as="a" href={child.statement_url} variant="secondary" icon={PrinterIcon} data-testid="print-statement">
                                            {t('invoices.my_print_statement')}
                                        </Button>
                                    </div>

                                    <ul className="mt-4 space-y-3" data-testid="my-invoices">
                                        {bills.map((i) => (
                                            <li key={i.id}>
                                                <div className="rounded-2xl border-2 border-stone-200 p-4 dark:border-stone-700" data-testid="my-invoice-card">
                                                    <div className="flex items-start justify-between gap-3">
                                                        <div>
                                                            <p className="text-lg font-bold text-stone-900 dark:text-stone-100">{i.period}</p>
                                                            <p className="text-sm text-stone-600 dark:text-stone-400">{day(i.period_start)} &middot; {i.invoice_number}</p>
                                                        </div>
                                                        <StatusBadge status={i.status} />
                                                    </div>
                                                    <p className="mt-3 text-lg font-semibold text-stone-900 dark:text-stone-100">
                                                        {Number(i.balance) > 0 ? t('invoices.my_balance', { balance: money(i.balance) }) : t('invoices.my_total_paid', { total: money(i.total), paid: money(i.paid) })}
                                                    </p>
                                                    {Number(i.balance) > 0 && i.due_date && (
                                                        <p className={`text-base font-medium ${i.is_overdue ? 'text-red-700 dark:text-red-400' : 'text-stone-600 dark:text-stone-400'}`} data-testid="my-invoice-due">
                                                            {i.is_overdue ? t('invoices.my_overdue_since', { date: day(i.due_date) }) : t('invoices.my_due_on', { date: day(i.due_date) })}
                                                        </p>
                                                    )}
                                                    {Number(i.balance) === 0 && i.status === 'paid' && i.paid_at && (
                                                        <p className="text-base text-stone-600 dark:text-stone-400">{t('invoices.my_paid_on', { date: day(i.paid_at) })}</p>
                                                    )}
                                                    <div className="mt-4">
                                                        <Button as={Link} href={route('invoices.show', i.id)} size="md" variant={Number(i.balance) > 0 ? 'primary' : 'secondary'}>{t('invoices.my_view_pay')}</Button>
                                                    </div>
                                                </div>
                                            </li>
                                        ))}
                                    </ul>
                                </Card>
                            ))
                        )}
                    </div>
                )}
            </Page>
        </AuthenticatedLayout>
    );
}
