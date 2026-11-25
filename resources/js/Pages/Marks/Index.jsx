import { BackLink, Card, EmptyState, Page } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { AcademicCapIcon, ChevronRightIcon, PencilSquareIcon, UserGroupIcon, UsersIcon } from '@heroicons/react/24/outline';
import { Link, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

// "What do I need to enter?": one card per CLASS (not per class+subject+exam, which used to turn into one
// long flat list that was hard to manage with several classes). Open a card to see its subjects, each with
// an exam row and where its marks stand -- see Marks/Section.jsx.

function ClassCard({ c }) {
    const { t } = useTranslation();

    return (
        <Link
            href={route('marks.section', c.section_id)}
            data-testid="class-card"
            className="flex items-center gap-4 rounded-2xl border-2 border-stone-200 bg-white p-5 transition hover:border-brand-400 hover:shadow-md focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-brand-200 dark:border-stone-700 dark:bg-stone-800 dark:hover:border-brand-500"
        >
            <div className="flex h-14 w-14 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-700 dark:bg-brand-900/30 dark:text-brand-300">
                <AcademicCapIcon className="h-8 w-8" aria-hidden="true" />
            </div>
            <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2">
                    <h2 className="truncate text-xl font-bold text-stone-900 dark:text-stone-100">{c.label}</h2>
                    {c.is_homeroom && (
                        <span className="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-semibold text-amber-900 dark:bg-amber-900/40 dark:text-amber-200">
                            <UserGroupIcon className="h-3.5 w-3.5" aria-hidden="true" />
                            {t('marks.class_homeroom_badge')}
                        </span>
                    )}
                </div>
                <div className="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1 text-base text-stone-600 dark:text-stone-400">
                    <span className="inline-flex items-center gap-1.5"><UsersIcon className="h-5 w-5" aria-hidden="true" />{t('marks.class_students', { count: c.students })}</span>
                    <span>{t('marks.class_subjects', { count: c.subjects })}</span>
                </div>
                {c.total > 0 && (
                    <div className="mt-3">
                        <div className="h-2 w-full max-w-xs overflow-hidden rounded-full bg-stone-200 dark:bg-stone-700">
                            <div className="h-2 rounded-full bg-emerald-600" style={{ width: `${Math.round((c.done / c.total) * 100)}%` }} />
                        </div>
                        <p className="mt-1 text-sm font-medium text-stone-600 dark:text-stone-400">{t('marks.class_progress', { done: c.done, total: c.total })}</p>
                    </div>
                )}
            </div>
            <ChevronRightIcon className="h-6 w-6 shrink-0 text-stone-400" aria-hidden="true" />
        </Link>
    );
}

export default function Index({ classes }) {
    const { t } = useTranslation();
    const { auth } = usePage().props;
    const isTeacher = auth.role === 'teacher';

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">{t('marks.title_enter')}</h2>}>
            <Page
                title={t('marks.title_enter')}
                description={t('marks.index_desc')}
                width="default"
                back={<BackLink big={isTeacher} href={route('dashboard')}>{t('common.back')}</BackLink>}
            >
                {classes.length === 0 ? (
                    <Card><EmptyState icon={PencilSquareIcon} title={t('marks.index_empty_title')}>{t('marks.index_empty_body')}</EmptyState></Card>
                ) : (
                    <div className="space-y-3" data-testid="class-cards">
                        {classes.map((c) => <ClassCard key={c.section_id} c={c} />)}
                    </div>
                )}
            </Page>
        </AuthenticatedLayout>
    );
}
