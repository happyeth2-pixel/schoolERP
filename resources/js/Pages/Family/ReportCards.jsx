import { Avatar } from '@/Components/ParentParts';
import { BackLink, Button, Card, EmptyState, Page } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { DocumentChartBarIcon, UserGroupIcon } from '@heroicons/react/24/outline';
import { Link } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

// One card per child, each with the two things a parent wants: look at the report card, or keep a PDF of it.

export default function ReportCards({ children }) {
    const { t } = useTranslation();
    const title = t('parent.report_page_title');

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">{title}</h2>}>
            <Page title={title} width="default" back={<BackLink big href={route('dashboard')}>{t('parent.back_home')}</BackLink>}>
                {children.length === 0 && <Card><EmptyState icon={UserGroupIcon} title={t('parent.no_child_title')}>{t('parent.no_child_body')}</EmptyState></Card>}
                {children.map((c, i) => (
                    <Card key={c.id} as="section" aria-label={c.name} data-testid="report-child">
                        <div className="flex items-center gap-3">
                            <Avatar name={c.name} index={i} />
                            <div className="min-w-0">
                                <p className="truncate text-xl font-bold text-stone-900 dark:text-stone-100">{c.name}</p>
                                <p className="text-base text-stone-700 dark:text-stone-300">{c.section ?? t('parent.no_class')}</p>
                            </div>
                        </div>
                        <div className="mt-4 flex flex-col gap-3 sm:flex-row">
                            <Button as={Link} href={route('report-card.show', c.id)} fullWidth icon={DocumentChartBarIcon}>{t('parent.report_view')}</Button>
                            <Button as="a" href={route('report-card.pdf', c.id)} variant="secondary" fullWidth>{t('parent.report_download')}</Button>
                        </div>
                    </Card>
                ))}
            </Page>
        </AuthenticatedLayout>
    );
}
