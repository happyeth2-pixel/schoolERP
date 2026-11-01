import { ActionTile, BackLink, Button, Page } from '@/Components/ui';
import SplitMeter from '@/Components/SplitMeter';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import {
    BanknotesIcon,
    ChartBarIcon,
    ClipboardDocumentCheckIcon,
    ClockIcon,
    ScaleIcon,
} from '@heroicons/react/24/outline';
import { Link, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

const money = (n) => `${Number(n).toLocaleString(undefined, { maximumFractionDigits: 0 })} ETB`;

export default function Overview({ installed, cash_total, revenue, expense, ar_total, ap_total, reconciled }) {
    const { t } = useTranslation();
    const { auth } = usePage().props;

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">{t('reports.overview_title')}</h2>}>
            <Page
                title={t('reports.overview_title')}
                back={<BackLink big={auth.role === 'teacher'} href={route('dashboard')}>{t('common.back')}</BackLink>}
            >
                {!installed ? (
                    <div className="rounded-xl border-2 border-stone-200 bg-white p-6 text-center dark:border-stone-700 dark:bg-stone-800">
                        <p className="text-lg font-semibold text-stone-900 dark:text-stone-100">{t('reports.overview_not_installed_title')}</p>
                        <p className="mt-2 text-base text-stone-600 dark:text-stone-400">{t('reports.overview_not_installed_body')}</p>
                        <Link href={route('accounts.index')}>
                            <Button className="mt-4">{t('reports.overview_go_setup')}</Button>
                        </Link>
                    </div>
                ) : (
                    <>
                        <div className="rounded-xl border-2 border-stone-200 bg-white p-6 text-center dark:border-stone-700 dark:bg-stone-800">
                            <p className="text-sm text-stone-500 dark:text-stone-400">{t('reports.overview_cash_label')}</p>
                            <p className="mt-1 text-4xl font-bold text-stone-900 dark:text-stone-100" data-testid="overview-cash">{money(cash_total)}</p>
                        </div>

                        <SplitMeter
                            label={t('reports.overview_money_in_out')}
                            paid={revenue}
                            total={revenue + expense}
                            emptyLabel={t('reports.no_activity')}
                        />
                        <SplitMeter
                            label={t('reports.overview_owed_to_us')}
                            paid={ar_total}
                            total={ar_total + ap_total}
                            emptyLabel={t('reports.no_activity')}
                        />

                        <div className="grid grid-cols-1 gap-4">
                            <ActionTile
                                icon={BanknotesIcon}
                                title={t('reports.overview_cash_tile_title')}
                                description={t('reports.overview_cash_tile_desc')}
                                href={route('reports.cash-summary')}
                            />
                            <ActionTile
                                icon={ChartBarIcon}
                                title={t('reports.overview_income_tile_title')}
                                description={t('reports.overview_income_tile_desc')}
                                href={route('reports.income-statement')}
                            />
                            <ActionTile
                                icon={ScaleIcon}
                                title={t('reports.overview_balance_tile_title')}
                                description={t('reports.overview_balance_tile_desc')}
                                href={route('reports.balance-sheet')}
                            />
                            <ActionTile
                                icon={ClockIcon}
                                title={t('reports.overview_ar_tile_title')}
                                description={t('reports.overview_ar_tile_desc')}
                                href={route('reports.ar-aging')}
                                badge={ar_total > 0 ? { text: money(ar_total), tone: 'warning' } : { text: t('reports.overview_ok_badge'), tone: 'success' }}
                            />
                            <ActionTile
                                icon={ClockIcon}
                                title={t('reports.overview_ap_tile_title')}
                                description={t('reports.overview_ap_tile_desc')}
                                href={route('reports.ap-aging')}
                                badge={ap_total > 0 ? { text: money(ap_total), tone: 'warning' } : { text: t('reports.overview_ok_badge'), tone: 'success' }}
                            />
                            <ActionTile
                                icon={ClipboardDocumentCheckIcon}
                                title={t('reports.overview_reconciliation_tile_title')}
                                description={t('reports.overview_reconciliation_tile_desc')}
                                href={route('reports.reconciliation')}
                                badge={
                                    reconciled === null
                                        ? undefined
                                        : reconciled
                                            ? { text: t('reports.overview_ok_badge'), tone: 'success' }
                                            : { text: t('reports.overview_problem_badge'), tone: 'danger' }
                                }
                            />
                        </div>
                    </>
                )}
            </Page>
        </AuthenticatedLayout>
    );
}
