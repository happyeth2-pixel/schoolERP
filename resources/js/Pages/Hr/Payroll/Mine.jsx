import { BackLink, Card, EmptyState, Page, SectionHeader } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { BanknotesIcon } from '@heroicons/react/24/outline';
import { usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

const money = (n) => Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

export default function Mine({ employee, payslips }) {
    const { t } = useTranslation();
    const { auth } = usePage().props;

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">{t('payroll.my_title')}</h2>}>
            <Page
                title={t('payroll.my_title')}
                description={t('payroll.my_desc')}
                back={<BackLink big={auth.role === 'teacher'} href={route('dashboard')}>{t('common.back')}</BackLink>}
            >
                {!employee ? (
                    <Card><EmptyState icon={BanknotesIcon} title={t('payroll.no_employee_record_title')}>{t('payroll.no_employee_record_body')}</EmptyState></Card>
                ) : (
                    <Card>
                        <SectionHeader as="h3" title={t('payroll.my_title')} />
                        {payslips.length === 0 ? (
                            <p className="text-base text-stone-600 dark:text-stone-400" data-testid="no-payslips">{t('payroll.no_payslips')}</p>
                        ) : (
                            <ul className="divide-y divide-stone-200 dark:divide-stone-700" data-testid="my-payslip-list">
                                {payslips.map((p) => (
                                    <li key={p.id} className="py-3" data-testid="my-payslip-row">
                                        <p className="text-base font-semibold text-stone-900 dark:text-stone-100">{p.period}</p>
                                        <dl className="mt-1 grid grid-cols-2 gap-1 text-sm text-stone-700 dark:text-stone-300 sm:grid-cols-4">
                                            <div><dt className="text-stone-500 dark:text-stone-400">{t('payroll.col_basic_salary')}</dt><dd>{money(p.basic_salary)}</dd></div>
                                            <div><dt className="text-stone-500 dark:text-stone-400">{t('payroll.col_income_tax')}</dt><dd>{money(p.income_tax)}</dd></div>
                                            <div><dt className="text-stone-500 dark:text-stone-400">{t('payroll.col_employee_pension')}</dt><dd>{money(p.employee_pension)}</dd></div>
                                            <div><dt className="text-stone-500 dark:text-stone-400">{t('payroll.col_net_pay')}</dt><dd className="font-semibold text-stone-900 dark:text-stone-100">{money(p.net_pay)}</dd></div>
                                        </dl>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>
                )}
            </Page>
        </AuthenticatedLayout>
    );
}
