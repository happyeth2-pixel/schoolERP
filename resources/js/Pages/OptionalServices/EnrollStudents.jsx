import { Alert, Button, Card, CheckRow, EmptyState, Field, FlashMessages, Input, Page, Select } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { ArrowLeftIcon, UserGroupIcon } from '@heroicons/react/24/outline';
import { Link, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';

// Enrolling several students in one extra service at once, instead of opening each student's own profile
// one at a time: pick a class, tick who should join, optionally set one price for everyone selected, send.
// Each student still goes through the exact same StudentServices::enroll() rule as the one-at-a-time path
// (one transport route at a time, immediate billing) -- this is a faster way to reach it, not a second rule.

export default function EnrollStudents({ service, sections, students, result }) {
    const { t } = useTranslation();
    const [sectionId, setSectionId] = useState(sections[0]?.id ?? '');
    const { data, setData, post, processing, errors } = useForm({ student_ids: [], custom_amount: '' });

    const roster = useMemo(() => students.filter((s) => String(s.section_id) === String(sectionId)), [students, sectionId]);
    const selectable = useMemo(() => roster.filter((s) => !s.already_enrolled), [roster]);
    const allSelected = selectable.length > 0 && selectable.every((s) => data.student_ids.includes(s.id));

    const toggle = (id) => {
        setData('student_ids', data.student_ids.includes(id) ? data.student_ids.filter((x) => x !== id) : [...data.student_ids, id]);
    };

    const selectAll = () => setData('student_ids', allSelected ? [] : selectable.map((s) => s.id));

    const submit = (e) => {
        e.preventDefault();
        post(route('optional-services.bulk-enroll', service.id), {
            preserveScroll: true,
            onSuccess: () => setData('student_ids', []),
        });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">{t('svc.enroll_title', { name: service.name })}</h2>}>
            <Page
                title={t('svc.enroll_title', { name: service.name })}
                description={t('svc.enroll_desc')}
                actions={<Button as={Link} href={route('optional-services.index')} variant="secondary" icon={ArrowLeftIcon}>{t('svc.back_to_service')}</Button>}
            >
                <FlashMessages />

                {result && (result.enrolled.length > 0 || result.skipped.length > 0) && (
                    <Alert tone="success" title={t('svc.result_title')}>
                        {result.enrolled.length > 0 && <p>{t('svc.result_enrolled', { count: result.enrolled.length, names: result.enrolled.join(', ') })}</p>}
                        {result.skipped.length > 0 && <p className="mt-1 text-amber-700 dark:text-amber-400">{t('svc.result_skipped', { count: result.skipped.length, names: result.skipped.join(', ') })}</p>}
                    </Alert>
                )}

                {sections.length === 0 ? (
                    <Card><EmptyState icon={UserGroupIcon} title={t('svc.no_students_title')}>{t('svc.no_students_body')}</EmptyState></Card>
                ) : (
                    <form onSubmit={submit}>
                        <Card className="space-y-5">
                            <Field id="section_id" label={t('svc.choose_class')}>
                                <Select id="section_id" value={sectionId} onChange={(e) => { setSectionId(e.target.value); setData('student_ids', []); }}>
                                    {sections.map((s) => <option key={s.id} value={s.id}>{s.label}</option>)}
                                </Select>
                            </Field>

                            {roster.length === 0 ? (
                                <p className="text-base text-stone-600 dark:text-stone-400">{t('svc.no_students_in_class')}</p>
                            ) : (
                                <div className="space-y-2">
                                    <div className="flex items-center justify-between">
                                        <p className="text-base font-medium text-stone-900 dark:text-stone-100">{t('svc.pick_students')}</p>
                                        {selectable.length > 0 && (
                                            <Button type="button" size="sm" variant="quiet" onClick={selectAll}>
                                                {allSelected ? t('svc.clear_selection') : t('svc.select_all')}
                                            </Button>
                                        )}
                                    </div>
                                    {roster.map((s) => (
                                        <CheckRow
                                            key={s.id}
                                            id={`student-${s.id}`}
                                            checked={data.student_ids.includes(s.id)}
                                            disabled={s.already_enrolled}
                                            onChange={() => toggle(s.id)}
                                            label={s.name}
                                            hint={s.already_enrolled ? t('svc.already_enrolled_badge') : s.student_id_number}
                                        />
                                    ))}
                                </div>
                            )}

                            <Field id="custom_amount" label={t('svc.custom_amount_label')} hint={t('svc.custom_amount_hint', { amount: service.amount })} optional error={errors.custom_amount}>
                                <Input id="custom_amount" type="number" step="0.01" min="0" value={data.custom_amount} onChange={(e) => setData('custom_amount', e.target.value)} />
                            </Field>

                            {errors.student_ids && <Alert tone="danger">{errors.student_ids}</Alert>}

                            <Button type="submit" busy={processing} disabled={data.student_ids.length === 0}>
                                {t('svc.enroll_button', { count: data.student_ids.length })}
                            </Button>
                        </Card>
                    </form>
                )}
            </Page>
        </AuthenticatedLayout>
    );
}
