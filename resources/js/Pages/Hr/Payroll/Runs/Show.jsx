import { Alert, BackLink, Button, Card, FlashMessages, Page, SectionHeader, StatusBadge, useConfirm } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

const STATUS_TONE = { draft: 'neutral', finalized: 'success' };
const money = (n) => Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

export default function Show({ run, canManage, skipped, payslips, totals }) {
    const { t } = useTranslation();
    const [ask, confirmDialog] = useConfirm();

    const finalize = async () => {
        if (await ask({
            title: t('payroll.finalize_confirm_title'),
            body: t('payroll.finalize_confirm_body'),
            confirmLabel: t('payroll.finalize_confirm_confirm'),
        })) {
            router.post(route('payroll-runs.finalize', run.id), {}, { preserveScroll: true });
        }
    };

    const remove = async () => {
        if (await ask({
            title: t('payroll.remove_run_title'),
            body: t('payroll.remove_run_body'),
            confirmLabel: t('payroll.remove_run_confirm'),
            tone: 'danger',
        })) {
            router.delete(route('payroll-runs.destroy', run.id));
        }
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">{run.branch} &middot; {run.label}</h2>}>
            <Page
                title={`${run.branch} · ${run.label}`}
                back={<BackLink href={route('payroll-runs.index')}>{t('payroll.runs_title')}</BackLink>}
                actions={canManage && run.status === 'draft' && (
                    <div className="flex gap-2">
                        <Button variant="secondary" onClick={remove}>{t('payroll.remove_button')}</Button>
                        <Button onClick={finalize}>{t('payroll.finalize_button')}</Button>
                    </div>
                )}
            >
                <FlashMessages errorKeys={['run']} />

                <div data-testid="run-status"><StatusBadge tone={STATUS_TONE[run.status]} label={t(`payroll.status_${run.status}`)} /></div>

                {skipped.length > 0 && (
                    <Alert tone="warning" title={t('payroll.skipped_title')}>
                        <ul data-testid="payroll-skipped-list">
                            {skipped.map((s, i) => <li key={i}>{t('payroll.skipped_reason_x', { name: s.employee, reason: s.reason })}</li>)}
                        </ul>
                    </Alert>
                )}

                <Card>
                    <SectionHeader as="h3" title={t('payroll.runs_title')} />
                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-sm" data-testid="payslip-table">
                            <thead>
                                <tr className="border-b border-stone-200 text-stone-600 dark:border-stone-700 dark:text-stone-400">
                                    <th className="py-2 pr-3">{t('payroll.col_employee')}</th>
                                    <th className="py-2 pr-3 text-right">{t('payroll.col_basic_salary')}</th>
                                    <th className="py-2 pr-3 text-right">{t('payroll.col_income_tax')}</th>
                                    <th className="py-2 pr-3 text-right">{t('payroll.col_employee_pension')}</th>
                                    <th className="py-2 pr-3 text-right">{t('payroll.col_employer_pension')}</th>
                                    <th className="py-2 text-right">{t('payroll.col_net_pay')}</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-stone-100 dark:divide-stone-800">
                                {payslips.map((p) => (
                                    <tr key={p.id} data-testid="payslip-row">
                                        <td className="py-2 pr-3 text-stone-900 dark:text-stone-100">{p.employee}</td>
                                        <td className="py-2 pr-3 text-right text-stone-800 dark:text-stone-200">{money(p.basic_salary)}</td>
                                        <td className="py-2 pr-3 text-right text-stone-800 dark:text-stone-200">{money(p.income_tax)}</td>
                                        <td className="py-2 pr-3 text-right text-stone-800 dark:text-stone-200">{money(p.employee_pension)}</td>
                                        <td className="py-2 pr-3 text-right text-stone-800 dark:text-stone-200">{money(p.employer_pension)}</td>
                                        <td className="py-2 text-right font-semibold text-stone-900 dark:text-stone-100">{money(p.net_pay)}</td>
                                    </tr>
                                ))}
                            </tbody>
                            <tfoot>
                                <tr className="border-t-2 border-stone-300 font-semibold text-stone-900 dark:border-stone-600 dark:text-stone-100" data-testid="payslip-totals-row">
                                    <td className="py-2 pr-3">{t('payroll.totals_label')}</td>
                                    <td className="py-2 pr-3 text-right">{money(totals.gross)}</td>
                                    <td className="py-2 pr-3 text-right">{money(totals.income_tax)}</td>
                                    <td className="py-2 pr-3 text-right">{money(totals.employee_pension)}</td>
                                    <td className="py-2 pr-3" />
                                    <td className="py-2 text-right">{money(totals.net_pay)}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </Card>
            </Page>
            {confirmDialog}
        </AuthenticatedLayout>
    );
}
