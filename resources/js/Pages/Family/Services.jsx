import { BackLink, Card, EmptyState, Page } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { TruckIcon } from '@heroicons/react/24/outline';
import { useTranslation } from 'react-i18next';

// What an extra service (a transport route, an after-school class, ...) actually costs, in one place --
// so a family can see the price before asking the school to enrol a child, or just check what they are
// already being billed for. Enrolling or withdrawing a child still happens through the school office.

const money = (n) => Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

export default function Services({ services, hasChildren }) {
    const { t } = useTranslation();
    const title = t('parent.services_title');

    const grouped = services.reduce((acc, s) => {
        (acc[s.category] ??= []).push(s);

        return acc;
    }, {});

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">{title}</h2>}>
            <Page title={title} description={t('parent.services_desc')} width="default" back={<BackLink big href={route('dashboard')}>{t('parent.back_home')}</BackLink>}>
                {!hasChildren ? (
                    <Card><EmptyState icon={TruckIcon} title={t('parent.no_child_title')}>{t('parent.no_child_body')}</EmptyState></Card>
                ) : services.length === 0 ? (
                    <Card><EmptyState icon={TruckIcon} title={t('parent.services_none_title')}>{t('parent.services_none_body')}</EmptyState></Card>
                ) : (
                    Object.entries(grouped).map(([category, list]) => (
                        <Card key={category} data-testid="service-category">
                            <h2 className="mb-3 text-xl font-bold text-stone-900 dark:text-stone-100">{t(`svc.kind_${category}`)}</h2>
                            <ul className="divide-y divide-stone-200 dark:divide-stone-700">
                                {list.map((s) => (
                                    <li key={s.id} className="flex flex-wrap items-center justify-between gap-3 py-4" data-testid="service-row">
                                        <div className="min-w-0">
                                            <p className="text-lg font-semibold text-stone-900 dark:text-stone-100">{s.name}</p>
                                            {s.enrolled_children.length > 0 && (
                                                <p className="mt-1 text-sm text-emerald-700 dark:text-emerald-400">{t('parent.services_enrolled', { names: s.enrolled_children.join(', ') })}</p>
                                            )}
                                        </div>
                                        <div className="text-right">
                                            <p className="text-xl font-bold text-stone-900 dark:text-stone-100">{money(s.amount)} ETB</p>
                                            <p className="text-sm text-stone-600 dark:text-stone-400">{t(`student.freq_${s.frequency}`)}</p>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        </Card>
                    ))
                )}
            </Page>
        </AuthenticatedLayout>
    );
}
