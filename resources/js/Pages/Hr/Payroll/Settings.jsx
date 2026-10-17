import PayrollTaxBracketDialog from '@/Components/PayrollTaxBracketDialog';
import { Alert, Button, Card, Field, FlashMessages, Input, Page, SectionHeader, useConfirm } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PlusIcon } from '@heroicons/react/24/outline';
import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

function PensionRatesForm({ employeeRate, employerRate, canManage }) {
    const { t } = useTranslation();
    const { data, setData, put, processing, errors } = useForm({
        pension_employee_rate: String(employeeRate * 100),
        pension_employer_rate: String(employerRate * 100),
    });

    const submit = (e) => {
        e.preventDefault();
        router.put(route('payroll-settings.pension'), {
            pension_employee_rate: String(Number(data.pension_employee_rate) / 100),
            pension_employer_rate: String(Number(data.pension_employer_rate) / 100),
        }, { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="space-y-6">
            <Field id="pension_employee_rate" label={t('payroll.pension_employee_label')} error={errors.pension_employee_rate}>
                <Input inputMode="decimal" value={data.pension_employee_rate} onChange={(e) => setData('pension_employee_rate', e.target.value)} disabled={!canManage} />
            </Field>
            <Field id="pension_employer_rate" label={t('payroll.pension_employer_label')} error={errors.pension_employer_rate}>
                <Input inputMode="decimal" value={data.pension_employer_rate} onChange={(e) => setData('pension_employer_rate', e.target.value)} disabled={!canManage} />
            </Field>
            {canManage && <Button type="submit" busy={processing}>{t('payroll.save_pension_button')}</Button>}
        </form>
    );
}

export default function Settings({ canManage, pensionEmployeeRate, pensionEmployerRate, brackets }) {
    const { t } = useTranslation();
    const [dialog, setDialog] = useState(null); // null | 'new' | a bracket object
    const [ask, confirmDialog] = useConfirm();

    const remove = async (bracket) => {
        if (await ask({
            title: t('payroll.remove_bracket_title'),
            body: t('payroll.remove_bracket_body'),
            confirmLabel: t('payroll.remove_bracket_confirm'),
            tone: 'danger',
        })) {
            router.delete(route('payroll-tax-brackets.destroy', bracket.id), { preserveScroll: true });
        }
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">{t('payroll.settings_title')}</h2>}>
            <Page title={t('payroll.settings_title')}>
                <FlashMessages errorKeys={['bracket']} />

                <Alert tone="warning" title={t('payroll.unverified_title')}>{t('payroll.unverified_body')}</Alert>

                <Card>
                    <SectionHeader as="h3" title={t('payroll.pension_section_title')} />
                    <PensionRatesForm employeeRate={pensionEmployeeRate} employerRate={pensionEmployerRate} canManage={canManage} />
                </Card>

                <Card>
                    <SectionHeader
                        as="h3"
                        title={t('payroll.brackets_section_title')}
                        action={canManage && <Button size="sm" icon={PlusIcon} onClick={() => setDialog('new')}>{t('payroll.add_bracket_button')}</Button>}
                    />
                    <p className="mb-4 text-sm text-gray-600 dark:text-gray-400">{t('payroll.brackets_desc')}</p>
                    <ul className="divide-y divide-gray-200 dark:divide-gray-700" data-testid="payroll-bracket-list">
                        {brackets.map((b) => (
                            <li key={b.id} className="flex flex-wrap items-center justify-between gap-2 py-3" data-testid="payroll-bracket-row">
                                <span className="text-base text-gray-900 dark:text-gray-100">
                                    {b.min_amount.toLocaleString()} &ndash; {b.max_amount != null ? b.max_amount.toLocaleString() : '∞'} &middot; {(b.rate * 100).toFixed(0)}% &minus; {b.deduction.toLocaleString()}
                                </span>
                                {canManage && (
                                    <div className="flex items-center gap-3">
                                        <Button size="sm" variant="quiet" onClick={() => setDialog(b)}>{t('payroll.edit_button')}</Button>
                                        <Button size="sm" variant="quiet" onClick={() => remove(b)}>{t('payroll.remove_button')}</Button>
                                    </div>
                                )}
                            </li>
                        ))}
                    </ul>
                </Card>
            </Page>
            {canManage && (
                <PayrollTaxBracketDialog
                    key={dialog === 'new' || dialog === null ? 'new' : `edit-${dialog.id}`}
                    open={dialog !== null}
                    bracket={dialog === 'new' ? null : dialog}
                    onClose={() => setDialog(null)}
                />
            )}
            {confirmDialog}
        </AuthenticatedLayout>
    );
}
