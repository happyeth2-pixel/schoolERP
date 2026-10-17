import { Button, Card, EmptyState, Page } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import {
    ArrowDownTrayIcon,
    ChartBarIcon,
    ClipboardDocumentListIcon,
    DocumentChartBarIcon,
    EyeIcon,
    ShieldCheckIcon,
} from '@heroicons/react/24/outline';
import { useTranslation } from 'react-i18next';

// One place that lists every report/export this system has, instead of each one only being
// reachable by already knowing its URL. Each entry's visibility was already decided server-side
// by ReportsController, using the EXACT permission its own page already enforces -- this page
// never decides on its own who sees what, it only renders what the controller already filtered.

const GROUP_ICON = { academic: ClipboardDocumentListIcon, finance: ChartBarIcon, accounting: DocumentChartBarIcon, people: ClipboardDocumentListIcon, oversight: ShieldCheckIcon };

export default function Index({ groups }) {
    const { t } = useTranslation();
    const title = t('reports.page_title');

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">{title}</h2>}>
            <Page title={title} description={t('reports.page_desc')} width="default">
                {groups.length === 0 ? (
                    <Card><EmptyState icon={DocumentChartBarIcon} title={t('reports.none_title')}>{t('reports.none_body')}</EmptyState></Card>
                ) : (
                    <div className="space-y-6">
                        {groups.map((group) => {
                            const GroupIcon = GROUP_ICON[group.key] ?? DocumentChartBarIcon;
                            return (
                                <section key={group.key} aria-label={t(`reports.group_${group.key}`)}>
                                    <h3 className="mb-3 flex items-center gap-2 text-lg font-bold text-gray-900 dark:text-gray-100">
                                        <GroupIcon className="h-5 w-5 text-indigo-600 dark:text-indigo-400" aria-hidden="true" />
                                        {t(`reports.group_${group.key}`)}
                                    </h3>
                                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                        {group.items.map((item) => (
                                            <Card key={item.key} data-testid="report-card">
                                                <p className="text-base font-semibold text-gray-900 dark:text-gray-100">{t(`reports.item_${item.key}`)}</p>
                                                <p className="mt-1 text-sm text-gray-600 dark:text-gray-400">{t(`reports.item_${item.key}_desc`)}</p>
                                                <Button
                                                    as="a"
                                                    href={item.url}
                                                    variant="secondary"
                                                    size="md"
                                                    icon={item.download ? ArrowDownTrayIcon : EyeIcon}
                                                    className="mt-4"
                                                    target={item.download ? undefined : '_self'}
                                                >
                                                    {t(item.download ? 'reports.download_button' : 'reports.view_button')}
                                                </Button>
                                            </Card>
                                        ))}
                                    </div>
                                </section>
                            );
                        })}
                    </div>
                )}
            </Page>
        </AuthenticatedLayout>
    );
}
