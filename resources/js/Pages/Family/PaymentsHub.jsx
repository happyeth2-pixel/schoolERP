import { ActionTile, BackLink, Page } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { BanknotesIcon, CheckCircleIcon, DocumentTextIcon } from '@heroicons/react/24/outline';
import { useTranslation } from 'react-i18next';

// Three buttons, nothing else -- all three land on the existing per-child bill timeline
// (family.invoices), already able to filter by status; this page just hands it the right filter.

export default function PaymentsHub() {
    const { t } = useTranslation();

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">{t('parent.fees_title')}</h2>}>
            <Page title={t('parent.fees_title')} width="narrow" back={<BackLink big href={route('dashboard')}>{t('parent.back_home')}</BackLink>}>
                <div className="space-y-4">
                    <ActionTile icon={DocumentTextIcon} title={t('parent.pay_hub_bills')} href={route('family.invoices')} tint="bg-emerald-700 text-white" />
                    <ActionTile icon={BanknotesIcon} title={t('parent.pay_hub_unpaid')} href={route('family.invoices', { status: 'owing' })} tint="bg-red-700 text-white" />
                    <ActionTile icon={CheckCircleIcon} title={t('parent.pay_hub_history')} href={route('family.invoices', { status: 'paid' })} tint="bg-sky-700 text-white" />
                </div>
            </Page>
        </AuthenticatedLayout>
    );
}
