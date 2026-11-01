import { BackLink, Button, PrintArea } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { ArrowDownTrayIcon, PrinterIcon } from '@heroicons/react/24/outline';
import { Head, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

export default function Show({ student, section, academic_year, subjects, overall_average, overall_letter, rank, class_size, missing_mark_policy, policy_note, weight_warnings, rank_reasons, rank_basis }) {
    const { t } = useTranslation();
    const { auth } = usePage().props;
    const isParent = auth.role === 'parent';

    return (
        <AuthenticatedLayout
            header={<h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">{t('parent.rc_title')} — {student.name}</h2>}
        >
            <Head title={`${t('parent.rc_title')} — ${student.name}`} />

            <div className="py-8">
                <div className="mx-auto max-w-2xl px-4 sm:px-6 lg:px-8">
                    <div className="mb-4">
                        <BackLink href={isParent ? route('family.report-cards') : route('students.edit', student.id)}>
                            {isParent ? t('parent.rc_back_to_list') : t('parent.rc_back_to_profile')}
                        </BackLink>
                    </div>

                    <div className="mb-4 flex flex-col gap-3 sm:flex-row" data-testid="report-actions">
                        <Button as="a" href={route('report-card.pdf', student.id)} fullWidth icon={ArrowDownTrayIcon}>{t('parent.rc_download')}</Button>
                        <Button variant="secondary" fullWidth icon={PrinterIcon} onClick={() => window.print()}>{t('parent.rc_print')}</Button>
                    </div>

                    {weight_warnings?.length > 0 && (
                        <div className="mb-4 rounded-md bg-amber-50 px-4 py-3 text-base text-amber-900 dark:bg-amber-900/30 dark:text-amber-200" data-testid="rc-warning">
                            {isParent ? <p>{t('parent.rc_not_ready')}</p> : weight_warnings.map((w) => <p key={w}>{w}</p>)}
                        </div>
                    )}

                    <PrintArea className="w-full">
                    <div className="rounded-lg bg-white p-6 shadow-sm dark:bg-stone-800">
                        <p className="text-sm text-stone-500">{academic_year}</p>
                        <p className="text-lg font-medium text-stone-900 dark:text-stone-100">{student.name}</p>
                        <p className="text-sm text-stone-400">{student.student_id_number} · {section ?? 'Unassigned'}</p>

                        <div className="mt-4 overflow-x-auto">
                        <table className="min-w-full divide-y divide-stone-200 dark:divide-stone-700">
                            <thead>
                                <tr>
                                    <th className="py-2 text-left text-sm font-semibold text-stone-600 dark:text-stone-400">{t('parent.rc_subject')}</th>
                                    <th className="py-2 text-left text-sm font-semibold text-stone-600 dark:text-stone-400">{t('parent.rc_average')}</th>
                                    <th className="py-2 text-left text-sm font-semibold text-stone-600 dark:text-stone-400">{t('parent.rc_grade')}</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-stone-100 dark:divide-stone-700">
                                {subjects.map((s) => (
                                    <tr key={s.subject}>
                                        <td className="py-2 text-sm text-stone-900 dark:text-stone-100">
                                            {s.subject}
                                            {s.assessments_missing > 0 && (
                                                <span className="block text-xs text-amber-600 dark:text-amber-400">
                                                    {missing_mark_policy === 'zero'
                                                        ? `${s.assessments_missing} of ${s.assessments_published} exams had no score — counted as 0`
                                                        : `Based on ${s.assessments_counted} of ${s.assessments_published} exams (${s.assessments_missing} had no score)`}
                                                </span>
                                            )}
                                        </td>
                                        <td className="py-2 text-sm text-stone-500">{s.average ?? '—'}</td>
                                        <td className="py-2 text-sm font-medium text-stone-900 dark:text-stone-100">{s.letter ?? '—'}</td>
                                    </tr>
                                ))}
                                {subjects.length === 0 && (
                                    <tr><td colSpan={3} className="py-4 text-center text-base text-stone-700 dark:text-stone-300">{t('parent.rc_none')}</td></tr>
                                )}
                            </tbody>
                        </table>
                        </div>

                        <div className="mt-4 grid grid-cols-3 gap-4 border-t border-stone-100 pt-4 dark:border-stone-700">
                            <div>
                                <p className="text-sm text-stone-600 dark:text-stone-400">{t('parent.rc_overall_avg')}</p>
                                <p className="text-lg font-semibold text-stone-900 dark:text-stone-100">{overall_average ?? '—'}</p>
                            </div>
                            <div>
                                <p className="text-sm text-stone-600 dark:text-stone-400">{t('parent.rc_overall_grade')}</p>
                                <p className="text-lg font-semibold text-stone-900 dark:text-stone-100">{overall_letter ?? '—'}</p>
                            </div>
                            <div>
                                <p className="text-sm text-stone-600 dark:text-stone-400">{t('parent.rc_rank')}</p>
                                <p className="text-lg font-semibold text-stone-900 dark:text-stone-100">{rank ? `${rank} / ${class_size}` : t('parent.rc_not_ranked')}</p>
                                {rank && rank_basis && (
                                    <p className="text-xs text-stone-400">among {class_size} ranked of {rank_basis.class_size} in class</p>
                                )}
                            </div>
                        </div>

                        {!rank && rank_reasons?.length > 0 && (
                            <div className="mt-3 rounded-md bg-stone-50 p-3 text-xs text-stone-600 dark:bg-stone-900/40 dark:text-stone-300">
                                <p className="font-medium">Why there is no class rank:</p>
                                <ul className="mt-1 list-disc pl-4">
                                    {rank_reasons.map((r) => <li key={r}>{r}</li>)}
                                </ul>
                            </div>
                        )}

                        {rank_basis && (
                            <details className="mt-3 text-xs text-stone-500 dark:text-stone-400">
                                <summary className="cursor-pointer font-medium text-brand-600 dark:text-brand-400">How is the class rank calculated?</summary>
                                <dl className="mt-2 space-y-1.5">
                                    <div><dt className="inline font-medium">Class: </dt><dd className="inline">{rank_basis.class}</dd></div>
                                    <div><dt className="inline font-medium">Subjects required: </dt><dd className="inline">{rank_basis.required_subjects.length ? rank_basis.required_subjects.join(', ') : 'none published yet'}</dd></div>
                                    <div><dt className="inline font-medium">Who is ranked: </dt><dd className="inline">{rank_basis.eligibility}</dd></div>
                                    <div><dt className="inline font-medium">Order: </dt><dd className="inline">{rank_basis.ordering}</dd></div>
                                    <div><dt className="inline font-medium">This class: </dt><dd className="inline">{rank_basis.ranked_count} ranked, {rank_basis.not_ranked_count} not ranked, {rank_basis.class_size} in total. (Rules v{rank_basis.rules_version})</dd></div>
                                </dl>
                            </details>
                        )}
                        {policy_note && (
                            <p className="mt-4 text-xs text-stone-400">
                                Only published results are included. Missing scores: {policy_note}
                            </p>
                        )}
                    </div>
                    </PrintArea>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
