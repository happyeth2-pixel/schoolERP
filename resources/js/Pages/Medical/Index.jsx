import { BackLink, Card, EmptyState, Field, Input, Page, StatusBadge } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { HeartIcon } from '@heroicons/react/24/outline';
import { Link, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';

// The principal/owner's triage view for medical records: WHO has something on file, never the content
// itself -- the same privacy discipline StudentMedicalRecordController keeps everywhere else (a flag, not
// the actual allergy/condition/medication text). Opening a row goes to that student's own medical page,
// where the real detail lives and where it is actually added or changed.
function AddMedicalInfoSearch() {
    const { t } = useTranslation();
    const [query, setQuery] = useState('');
    const [results, setResults] = useState([]);
    const [searching, setSearching] = useState(false);

    useEffect(() => {
        if (query.trim().length < 2) { setResults([]); return undefined; }
        setSearching(true);
        const handle = setTimeout(() => {
            fetch(route('medical-records.student-search', { q: query.trim() }), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                .then((r) => (r.ok ? r.json() : []))
                .then(setResults)
                .finally(() => setSearching(false));
        }, 300);
        return () => clearTimeout(handle);
    }, [query]);

    return (
        <Card data-testid="add-medical-search">
            <Field id="medical_student_search" label={t('medical.add_for_student')} hint={t('medical.add_for_student_hint')}>
                <Input value={query} onChange={(e) => setQuery(e.target.value)} autoComplete="off" placeholder={t('medical.search_placeholder')} />
            </Field>
            {query.trim().length >= 2 && (
                <ul className="mt-2 divide-y divide-stone-200 rounded-xl border-2 border-stone-200 dark:divide-stone-700 dark:border-stone-700" data-testid="medical-search-results">
                    {searching && <li className="p-3 text-sm text-stone-600 dark:text-stone-400">{t('common.searching')}</li>}
                    {!searching && results.length === 0 && <li className="p-3 text-sm text-stone-600 dark:text-stone-400">{t('medical.no_students_found')}</li>}
                    {results.map((r) => (
                        <li key={r.id}>
                            <Link href={route('students.medical.show', r.id)} className="flex items-center justify-between gap-3 p-3 hover:bg-stone-50 dark:hover:bg-stone-700">
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

export default function Index({ students, totalEnrolled, canManage }) {
    const { t } = useTranslation();
    const { auth } = usePage().props;

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">{t('medical.dashboard_title')}</h2>}>
            <Page
                title={t('medical.dashboard_title')}
                description={t('medical.dashboard_desc', { onFile: students.length, total: totalEnrolled })}
                width="default"
                back={<BackLink big={auth.role === 'teacher'} href={route('dashboard')}>{t('common.back')}</BackLink>}
            >
                {canManage && <AddMedicalInfoSearch />}

                {students.length === 0 ? (
                    <Card><EmptyState icon={HeartIcon} title={t('medical.none_title')}>{t('medical.none_body')}</EmptyState></Card>
                ) : (
                    <div className="space-y-3" data-testid="medical-list">
                        {students.map((s) => (
                            <Link key={s.id} href={route('students.medical.show', s.id)} data-testid="medical-row" className="block rounded-2xl border-2 border-stone-200 bg-white p-5 hover:border-brand-400 dark:border-stone-700 dark:bg-stone-800">
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <div>
                                        <p className="text-base font-semibold text-stone-900 dark:text-stone-100">{s.name}</p>
                                        <p className="text-sm text-stone-600 dark:text-stone-400">{s.class} · {t('medical.updated_x', { when: s.updated_at })}</p>
                                    </div>
                                    <div className="flex flex-wrap gap-2">
                                        {s.blood_type && <StatusBadge tone="info" label={s.blood_type} />}
                                        {s.has_allergies && <StatusBadge tone="warning" label={t('medical.flag_allergies')} />}
                                        {s.has_conditions && <StatusBadge tone="warning" label={t('medical.flag_conditions')} />}
                                        {s.has_medications && <StatusBadge tone="info" label={t('medical.flag_medications')} />}
                                    </div>
                                </div>
                            </Link>
                        ))}
                    </div>
                )}
            </Page>
        </AuthenticatedLayout>
    );
}
