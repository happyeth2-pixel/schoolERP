import { Card, EmptyState, Page, SectionHeader } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { BanknotesIcon } from '@heroicons/react/24/outline';
import { useTranslation } from 'react-i18next';

const money = (n) => Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

export default function Mine({ employee, payslips }) {
    const { t } = useTranslation();

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">{t('payroll.my_title')}</h2>}>
            <Page title={t('payroll.my_title')} description={t('payroll.my_desc')}>
                {!employee ? (
                    <Card><EmptyState icon={BanknotesIcon} title={t('payroll.no_employee_record_title')}>{t('payroll.no_employee_record_body')}</EmptyState></Card>
                ) : (
                    <Card>
                        <SectionHeader as="h3" title={t('payroll.my_title')} />
                        {payslips.length === 0 ? (
                            <p className="text-base text-gray-600 dark:text-gray-400" data-testid="no-payslips">{t('payroll.no_payslips')}</p>
                        ) : (
                            <ul className="divide-y divide-gray-200 dark:divide-gray-700" data-testid="my-payslip-list">
                                {payslips.map((p) => (
                                    <li key={p.id} className="py-3" data-testid="my-payslip-row">
                                        <p className="text-base font-semibold text-gray-900 dark:text-gray-100">{p.period}</p>
                                        <dl className="mt-1 grid grid-cols-2 gap-1 text-sm text-gray-700 dark:text-gray-300 sm:grid-cols-4">
                                            <div><dt className="text-gray-500 dark:text-gray-400">{t('payroll.col_basic_salary')}</dt><dd>{money(p.basic_salary)}</dd></div>
                                            <div><dt className="text-gray-500 dark:text-gray-400">{t('payroll.col_income_tax')}</dt><dd>{money(p.income_tax)}</dd></div>
                                            <div><dt className="text-gray-500 dark:text-gray-400">{t('payroll.col_employee_pension')}</dt><dd>{money(p.employee_pension)}</dd></div>
                                            <div><dt className="text-gray-500 dark:text-gray-400">{t('payroll.col_net_pay')}</dt><dd className="font-semibold text-gray-900 dark:text-gray-100">{money(p.net_pay)}</dd></div>
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
