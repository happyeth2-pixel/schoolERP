import { BackLink, Button, StatusBadge } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PlusIcon } from '@heroicons/react/24/outline';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

export default function Index({ complaints, isStaffView, statusFilter }) {
    const { t } = useTranslation();
    const { flash } = usePage().props;
    const title = isStaffView ? t('complaints.page_title_staff') : t('complaints.page_title_mine');

    const setFilter = (status) => {
        router.get(route('complaints.index'), status ? { status } : {}, { preserveState: true });
    };

    const FILTERS = [
        [null, t('complaints.filter_all')],
        ['open', t('complaints.filter_open')],
        ['in_progress', t('complaints.filter_in_progress')],
        ['resolved', t('complaints.filter_resolved')],
    ];

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">{title}</h2>}>
            <Head title={title} />

            <div className="py-8">
                <div className="mx-auto max-w-4xl space-y-4 px-4 sm:px-6 lg:px-8">
                    <BackLink href={route('dashboard')}>{t('common.back')}</BackLink>

                    {flash?.success && (
                        <div className="rounded-md bg-green-50 px-4 py-3 text-sm text-green-700 dark:bg-green-900/30 dark:text-green-300">
                            {flash.success}
                        </div>
                    )}

                    <div className="flex flex-wrap items-center justify-between gap-3">
                        {isStaffView ? (
                            <div className="flex gap-2 text-sm">
                                {FILTERS.map(([value, label]) => (
                                    <button
                                        key={label}
                                        onClick={() => setFilter(value)}
                                        className={`rounded-full px-3 py-1 ${(statusFilter ?? null) === value ? 'bg-brand-600 text-white' : 'bg-stone-100 text-stone-600 dark:bg-stone-700 dark:text-stone-300'}`}
                                    >
                                        {label}
                                    </button>
                                ))}
                            </div>
                        ) : (
                            <div />
                        )}
                        {!isStaffView && (
                            <Button as={Link} href={route('complaints.create')} icon={PlusIcon}>{t('complaints.new_button')}</Button>
                        )}
                    </div>

                    <div className="overflow-x-auto rounded-lg bg-white shadow-sm dark:bg-stone-800">
                        <table className="min-w-full divide-y divide-stone-200 dark:divide-stone-700">
                            <thead className="bg-stone-50 dark:bg-stone-900/40">
                                <tr>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-stone-500">{t('complaints.col_subject')}</th>
                                    {isStaffView && <th className="px-4 py-3 text-left text-xs font-medium uppercase text-stone-500">{t('complaints.col_from')}</th>}
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-stone-500">{t('complaints.col_student')}</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-stone-500">{t('complaints.col_status')}</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-stone-500">{t('complaints.col_submitted')}</th>
                                    <th className="px-4 py-3" />
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-stone-200 dark:divide-stone-700">
                                {complaints.map((c) => (
                                    <tr key={c.id}>
                                        <td className="px-4 py-3 text-sm text-stone-900 dark:text-stone-100">{c.subject}</td>
                                        {isStaffView && <td className="px-4 py-3 text-sm text-stone-500">{c.submitted_by}</td>}
                                        <td className="px-4 py-3 text-sm text-stone-500">{c.student ? (c.student.restricted ? <span className="italic text-stone-400">{t('complaints.restricted')}</span> : `${c.student.name} — ${c.student.section}`) : '—'}</td>
                                        <td className="px-4 py-3 text-sm">
                                            <StatusBadge status={c.status} />
                                        </td>
                                        <td className="px-4 py-3 text-sm text-stone-500">{c.created_at}</td>
                                        <td className="px-4 py-3 text-right text-sm">
                                            <Link href={route('complaints.show', c.id)} className="text-brand-600 hover:underline dark:text-brand-400">{t('complaints.view_link')}</Link>
                                        </td>
                                    </tr>
                                ))}
                                {complaints.length === 0 && (
                                    <tr>
                                        <td colSpan={isStaffView ? 6 : 5} className="px-4 py-6 text-center text-sm text-stone-400">
                                            {isStaffView ? t('complaints.none_staff') : t('complaints.none_mine')}
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
