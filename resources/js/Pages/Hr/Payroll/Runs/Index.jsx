import { Button, Card, EmptyState, Field, FlashMessages, Input, Page, Select, StatusBadge } from '@/Components/ui';
import Modal from '@/Components/Modal';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { BanknotesIcon, PlusIcon } from '@heroicons/react/24/outline';
import { Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

const STATUS_TONE = { draft: 'neutral', finalized: 'success' };

function GenerateDialog({ open, branches, fixedBranch, onClose }) {
    const { t } = useTranslation();
    const { data, setData, post, processing, errors, reset } = useForm({
        branch_id: fixedBranch?.id ?? (branches[0]?.id ?? ''),
        period: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('payroll-runs.store'), { onSuccess: () => reset() });
    };

    return (
        <Modal show={open} onClose={onClose} maxWidth="lg">
            {open && (
                <form onSubmit={submit} className="space-y-6 p-6" data-testid="payroll-generate-form">
                    <h2 className="text-2xl font-bold text-gray-900 dark:text-gray-100">{t('payroll.generate_title')}</h2>
                    {fixedBranch ? (
                        <p className="text-base text-gray-800 dark:text-gray-200" data-testid="fixed-branch">{fixedBranch.name}</p>
                    ) : (
                        <Field id="run_branch" label={t('payroll.branch_label')} error={errors.branch_id}>
                            <Select id="run_branch" value={data.branch_id} onChange={(e) => setData('branch_id', e.target.value)}>
                                {branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
                            </Select>
                        </Field>
                    )}
                    <Field id="run_period" label={t('payroll.period_label')} error={errors.period}>
                        <Input type="month" value={data.period} onChange={(e) => setData('period', e.target.value)} />
                    </Field>
                    <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        <Button type="button" variant="secondary" onClick={onClose}>{t('common.cancel')}</Button>
                        <Button type="submit" busy={processing} disabled={!data.branch_id || !data.period}>{t('payroll.generate_submit_button')}</Button>
                    </div>
                </form>
            )}
        </Modal>
    );
}

export default function Index({ runs, canManage, branches, fixedBranch }) {
    const { t } = useTranslation();
    const [dialog, setDialog] = useState(false);

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">{t('payroll.runs_title')}</h2>}>
            <Page
                title={t('payroll.runs_title')}
                description={t('payroll.runs_desc')}
                actions={canManage && <Button icon={PlusIcon} onClick={() => setDialog(true)}>{t('payroll.generate_button')}</Button>}
            >
                <FlashMessages />

                {runs.length === 0 ? (
                    <Card><EmptyState icon={BanknotesIcon} title={t('payroll.no_runs')} /></Card>
                ) : (
                    <Card>
                        <ul className="divide-y divide-gray-200 dark:divide-gray-700" data-testid="payroll-run-list">
                            {runs.map((r) => (
                                <li key={r.id} className="py-3" data-testid="payroll-run-row">
                                    <Link href={route('payroll-runs.show', r.id)} className="flex flex-wrap items-center justify-between gap-2 hover:underline">
                                        <span className="text-base font-semibold text-gray-900 dark:text-gray-100">{r.branch} &middot; {r.label}</span>
                                        <span className="flex items-center gap-2">
                                            <span className="text-sm text-gray-600 dark:text-gray-400">{r.payslips_count}</span>
                                            <StatusBadge tone={STATUS_TONE[r.status]} label={t(`payroll.status_${r.status}`)} />
                                        </span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </Card>
                )}
            </Page>
            {canManage && <GenerateDialog open={dialog} branches={branches} fixedBranch={fixedBranch} onClose={() => setDialog(false)} />}
        </AuthenticatedLayout>
    );
}
