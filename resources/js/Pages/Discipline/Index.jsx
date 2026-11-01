import DateText from '@/Components/DateText';
import { BackLink, Card, EmptyState, Field, Input, Page, Select, StatusBadge } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { ExclamationTriangleIcon } from '@heroicons/react/24/outline';
import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';

const STATUS_TONE = { open: 'warning', under_review: 'info', resolved: 'success' };

/** Search-as-you-type for "report an incident", so this doesn't have to start from the student's own
 * profile -- the same shape IssueLoanDialog already uses for "who is this for?" searches. Picking a result
 * takes the person straight to that student's discipline page (Discipline/ForStudent.jsx), where the
 * actual form lives -- this never duplicates it. */
function ReportIncidentSearch() {
    const { t } = useTranslation();
    const [query, setQuery] = useState('');
    const [results, setResults] = useState([]);
    const [searching, setSearching] = useState(false);

    useEffect(() => {
        if (query.trim().length < 2) { setResults([]); return undefined; }
        setSearching(true);
        const handle = setTimeout(() => {
            fetch(route('discipline.student-search', { q: query.trim() }), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                .then((r) => (r.ok ? r.json() : []))
                .then(setResults)
                .finally(() => setSearching(false));
        }, 300);
        return () => clearTimeout(handle);
    }, [query]);

    return (
        <Card data-testid="report-incident-search">
            <Field id="incident_student_search" label={t('discipline.report_incident')} hint={t('discipline.report_incident_hint')}>
                <Input value={query} onChange={(e) => setQuery(e.target.value)} autoComplete="off" placeholder={t('discipline.search_placeholder')} />
            </Field>
            {query.trim().length >= 2 && (
                <ul className="mt-2 divide-y divide-stone-200 rounded-xl border-2 border-stone-200 dark:divide-stone-700 dark:border-stone-700" data-testid="incident-search-results">
                    {searching && <li className="p-3 text-sm text-stone-600 dark:text-stone-400">{t('common.searching')}</li>}
                    {!searching && results.length === 0 && <li className="p-3 text-sm text-stone-600 dark:text-stone-400">{t('discipline.no_students_found')}</li>}
                    {results.map((r) => (
                        <li key={r.id}>
                            <Link href={route('discipline.for-student', r.id)} className="flex items-center justify-between gap-3 p-3 hover:bg-stone-50 dark:hover:bg-stone-700">
                                <span className="font-medium text-stone-900 dark:text-stone-100">{r.name}</span>
                                <span className="text-sm text-stone-600 dark:text-stone-400">{r.detail}</span>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </Card>
    );
}

// The school-wide triage view: every incident across every student, filterable by status, plus a search to
// report a new incident for any student without first opening their own profile. Editing a specific
// incident still happens on that student's own discipline page (Discipline/ForStudent.jsx) -- this page is
// for finding what needs attention and starting a new report, not for data entry itself.
export default function Index({ incidents, status, canManage }) {
    const { t } = useTranslation();
    const { auth } = usePage().props;

    const filter = (value) => router.get(route('discipline.index'), value ? { status: value } : {}, { preserveState: true });

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">{t('discipline.dashboard_title')}</h2>}>
            <Page
                title={t('discipline.dashboard_title')}
                description={t('discipline.dashboard_desc')}
                width="default"
                back={<BackLink big={auth.role === 'teacher'} href={route('dashboard')}>{t('common.back')}</BackLink>}
            >
                {canManage && <ReportIncidentSearch />}

                <Card>
                    <Select data-testid="status-filter" value={status ?? ''} onChange={(e) => filter(e.target.value)}>
                        <option value="">{t('discipline.all_statuses')}</option>
                        <option value="open">{t('discipline.status_open')}</option>
                        <option value="under_review">{t('discipline.status_under_review')}</option>
                        <option value="resolved">{t('discipline.status_resolved')}</option>
                    </Select>
                </Card>

                {incidents.length === 0 ? (
                    <Card><EmptyState icon={ExclamationTriangleIcon} title={t('discipline.none_title')}>{t('discipline.dashboard_none_body')}</EmptyState></Card>
                ) : (
                    <div className="space-y-3">
                        {incidents.map((i) => (
                            <Link key={i.id} href={route('discipline.for-student', i.student_id)} data-testid="dashboard-incident-row" className="block rounded-2xl border-2 border-stone-200 bg-white p-5 hover:border-brand-400 dark:border-stone-700 dark:bg-stone-800">
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <div>
                                        <p className="text-base font-semibold text-stone-900 dark:text-stone-100">{i.student_name} · {t(`discipline.category_${i.category}`)}</p>
                                        <p className="text-sm text-stone-600 dark:text-stone-400"><DateText value={i.incident_date} /> · {t('discipline.reported_by_x', { name: i.reported_by })}</p>
                                    </div>
                                    <StatusBadge tone={STATUS_TONE[i.status]} label={t(`discipline.status_${i.status}`)} />
                                </div>
                            </Link>
                        ))}
                    </div>
                )}
            </Page>
        </AuthenticatedLayout>
    );
}
