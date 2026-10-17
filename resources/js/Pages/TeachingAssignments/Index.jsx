import { Alert, Button, Card, EmptyState, FlashMessages, Input, Page, SectionHeader, Select, StatusBadge, useConfirm } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { ArrowLeftIcon, CheckIcon } from '@heroicons/react/24/outline';
import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

// "Who teaches what, in which class." The page starts from the CLASS (a section such as Grade 5 B): pick the class,
// then see its class teacher and every subject with its teacher, and give a subject to a teacher in two taps.
// Nothing about the rules moved: the same endpoints check the branch, that the teacher is active and in the same
// branch as the class, that a subject has exactly one teacher per class, and that replacing one is deliberate.

function ClassTeacher({ section, teachers, canSet }) {
    const { t } = useTranslation();
    const { data, setData, patch, processing } = useForm({ class_teacher_id: section.class_teacher?.id ?? '' });
    const unchanged = String(data.class_teacher_id) === String(section.class_teacher?.id ?? '');

    return (
        <Card data-testid="class-teacher">
            <SectionHeader as="h3" title={t('teach.ct_title')} description={t('teach.ct_desc')} />
            {section.class_teacher
                ? <p className="text-lg font-semibold text-gray-900 dark:text-gray-100">{section.class_teacher.name}</p>
                : <p className="text-lg font-semibold text-amber-800 dark:text-amber-300">{t('teach.ct_none')}</p>}
            {canSet ? (
                <form
                    className="mt-4 space-y-3"
                    onSubmit={(e) => { e.preventDefault(); patch(route('sections.class-teacher', section.id), { preserveScroll: true }); }}
                >
                    <label htmlFor="class-teacher-select" className="block text-base font-medium text-gray-900 dark:text-gray-100">{t('teach.ct_pick')}</label>
                    <Select id="class-teacher-select" value={data.class_teacher_id} onChange={(e) => setData('class_teacher_id', e.target.value)}>
                        <option value="">{t('teach.ct_no_one')}</option>
                        {teachers.map((tc) => <option key={tc.id} value={tc.id}>{tc.name}</option>)}
                    </Select>
                    <Button type="submit" busy={processing} disabled={unchanged}>{data.class_teacher_id === '' ? t('teach.ct_clear', { class: `${section.grade} ${section.name}` }) : t('teach.ct_save', { class: `${section.grade} ${section.name}` })}</Button>
                </form>
            ) : (
                <p className="mt-3 text-base text-gray-600 dark:text-gray-400">{t('teach.ct_readonly')}</p>
            )}
        </Card>
    );
}

function Subjects({ section, subjects, teachers }) {
    const { t } = useTranslation();
    const [ask, confirmDialog] = useConfirm();
    const { data, setData, post, processing, errors, transform, reset, clearErrors } = useForm({ section_id: section.id, subject_id: '', teacher_id: '', replace: false });
    const className = `${section.grade} ${section.name}`;

    const subject = subjects.find((s) => String(s.id) === String(data.subject_id));
    const teacher = teachers.find((tc) => String(tc.id) === String(data.teacher_id));
    const current = section.assignments.find((a) => String(a.subject_id) === String(data.subject_id));
    const same = current && teacher && current.teacher === teacher.name;

    const remove = async (a) => {
        const ok = await ask({
            title: t('teach.remove_title', { subject: a.subject, class: className }),
            body: <p>{t('teach.remove_body', { teacher: a.teacher, subject: a.subject, class: className })}</p>,
            confirmLabel: t('teach.remove_button', { subject: a.subject }),
        });
        if (ok) router.delete(route('teaching-assignments.destroy', a.id), { preserveScroll: true });
    };

    const save = async () => {
        let replace = false;
        if (current) {
            const ok = await ask({
                tone: 'info',
                title: t('teach.replace_title', { subject: subject.name, class: className }),
                body: <><p>{t('teach.replace_body', { current: current.teacher, chosen: teacher.name, subject: subject.name })}</p><p>{t('teach.replace_marks')}</p></>,
                confirmLabel: t('teach.replace_button', { chosen: teacher.name, subject: subject.name }),
            });
            if (!ok) return;
            replace = true;
        }
        transform((d) => ({ ...d, replace }));
        post(route('teaching-assignments.store'), { preserveScroll: true, onSuccess: () => reset('subject_id', 'teacher_id') });
    };

    return (
        <>
            <Card data-testid="subjects">
                <SectionHeader as="h3" title={t('teach.subjects_title', { class: className })} description={t('teach.subjects_desc')} />
                {section.assignments.length === 0 ? (
                    <p className="rounded-xl bg-gray-50 p-4 text-base text-gray-700 dark:bg-gray-900/40 dark:text-gray-300">{t('teach.subjects_none')}</p>
                ) : (
                    <ul className="divide-y divide-gray-200 dark:divide-gray-700">
                        {section.assignments.map((a) => (
                            <li key={a.id} data-testid="assignment" className="flex flex-wrap items-center justify-between gap-3 py-3">
                                <p className="text-lg text-gray-900 dark:text-gray-100"><span className="font-semibold">{a.subject}</span> <span className="text-gray-600 dark:text-gray-400">— {a.teacher}</span></p>
                                <div className="flex gap-2">
                                    <Button size="md" variant="secondary" onClick={() => { clearErrors(); setData((d) => ({ ...d, subject_id: String(a.subject_id), teacher_id: '' })); document.getElementById('give-subject')?.scrollIntoView({ block: 'center' }); }}>{t('teach.change_teacher')}</Button>
                                    <Button size="md" variant="quiet" onClick={() => remove(a)}>{t('teach.remove')}</Button>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </Card>

            <Card data-testid="give-subject" id="give-subject">
                <SectionHeader as="h3" title={t('teach.give_title', { class: className })} description={t('teach.give_desc')} />
                {subjects.length === 0 || teachers.length === 0 ? (
                    <Alert tone="warning" title={subjects.length === 0 ? t('teach.no_subjects_title') : t('teach.no_teachers_title', { branch: section.branch })}>
                        {subjects.length === 0 ? t('teach.no_subjects_body') : t('teach.no_teachers_body')}
                    </Alert>
                ) : (
                    <div className="space-y-6">
                        <div>
                            <p className="mb-2 text-lg font-semibold text-gray-900 dark:text-gray-100">{t('teach.q_subject')}</p>
                            <div role="radiogroup" aria-label={t('teach.q_subject')} className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                                {subjects.map((s) => {
                                    const selected = String(s.id) === String(data.subject_id);
                                    const taught = section.assignments.find((a) => a.subject_id === s.id);
                                    return (
                                        <button key={s.id} type="button" role="radio" aria-checked={selected} onClick={() => { clearErrors(); setData('subject_id', String(s.id)); }}
                                            className={`flex min-h-[64px] flex-col items-center justify-center rounded-xl border-2 px-3 py-2 text-center transition focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 ${selected ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-gray-300 bg-white text-gray-900 hover:border-indigo-400 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100'}`}>
                                            <span className="text-base font-bold">{s.name}</span>
                                            <span className={`text-sm ${selected ? 'text-indigo-100' : 'text-gray-600 dark:text-gray-400'}`}>{taught ? t('teach.taught_by', { teacher: taught.teacher }) : t('teach.no_teacher_yet')}</span>
                                        </button>
                                    );
                                })}
                            </div>
                        </div>

                        {subject && (
                            <div>
                                <label htmlFor="give-teacher" className="mb-2 block text-lg font-semibold text-gray-900 dark:text-gray-100">{t('teach.q_teacher', { subject: subject.name })}</label>
                                <Select id="give-teacher" value={data.teacher_id} onChange={(e) => { clearErrors(); setData('teacher_id', e.target.value); }}>
                                    <option value="">{t('teach.choose_teacher')}</option>
                                    {teachers.map((tc) => <option key={tc.id} value={tc.id}>{tc.name}</option>)}
                                </Select>
                                {errors.teacher_id && <p role="alert" className="mt-2 text-sm font-medium text-red-700 dark:text-red-400">{errors.teacher_id}</p>}
                            </div>
                        )}

                        {subject && teacher && (
                            <div className="space-y-3" data-testid="give-summary">
                                <p className="rounded-xl border-2 border-indigo-300 bg-indigo-50 p-4 text-lg font-semibold text-gray-900 dark:border-indigo-700 dark:bg-indigo-900/20 dark:text-gray-100">
                                    {t('teach.summary', { teacher: teacher.name, subject: subject.name, class: className })}
                                </p>
                                {same && <Alert tone="info">{t('teach.already', { teacher: teacher.name, subject: subject.name })}</Alert>}
                                {current && !same && <Alert tone="warning" title={t('teach.replace_warn_title')}>{t('teach.replace_warn_body', { current: current.teacher, subject: subject.name })}</Alert>}
                                <Button variant="success" icon={CheckIcon} onClick={save} busy={processing} disabled={Boolean(same)}>{current && !same ? t('teach.replace_button', { chosen: teacher.name, subject: subject.name }) : t('teach.give_button', { teacher: teacher.name, subject: subject.name })}</Button>
                            </div>
                        )}
                    </div>
                )}
            </Card>
            {confirmDialog}
        </>
    );
}

export default function Index({ sections, teachers, subjects, canSetClassTeacher, focus, branchCount }) {
    const { t } = useTranslation();
    const [selectedId, setSelectedId] = useState(focus && sections.some((s) => s.id === focus) ? focus : null);
    const [filter, setFilter] = useState('');
    const selected = sections.find((s) => s.id === selectedId);

    const header = <h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">{t('teach.page_title')}</h2>;

    if (selected) {
        const teachersHere = teachers.filter((tc) => tc.branch_id === selected.branch_id);

        return (
            <AuthenticatedLayout header={header}>
                <Page
                    title={t('teach.class_title', { class: `${selected.grade} ${selected.name}` })}
                    description={branchCount > 1 ? selected.branch : undefined}
                    width="default"
                    back={<button type="button" onClick={() => setSelectedId(null)} className="-ml-2 inline-flex min-h-[48px] items-center gap-2 rounded-xl px-2 text-base font-medium text-indigo-700 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 dark:text-indigo-300"><ArrowLeftIcon className="h-5 w-5" aria-hidden="true" />{t('teach.back_to_classes')}</button>}
                >
                    <FlashMessages errorKeys={['delete']} />
                    <ClassTeacher key={`${selected.id}-${selected.class_teacher?.id ?? 'none'}`} section={selected} teachers={teachersHere} canSet={canSetClassTeacher} />
                    <Subjects key={selected.id} section={selected} subjects={subjects} teachers={teachersHere} />
                </Page>
            </AuthenticatedLayout>
        );
    }

    const q = filter.trim().toLowerCase();
    const shown = q ? sections.filter((s) => `${s.grade} ${s.name} ${s.branch}`.toLowerCase().includes(q)) : sections;

    return (
        <AuthenticatedLayout header={header}>
            <Page title={t('teach.page_title')} description={t('teach.page_desc')} width="wide">
                <FlashMessages />
                {teachers.length === 0 && <Alert tone="warning" title={t('teach.no_teacher_accounts')}>{t('teach.no_teacher_accounts_body')}</Alert>}
                {sections.length > 6 && (
                    <div className="max-w-md">
                        <label htmlFor="class-filter" className="mb-1 block text-base font-medium text-gray-900 dark:text-gray-100">{t('teach.find_class')}</label>
                        <Input id="class-filter" value={filter} onChange={(e) => setFilter(e.target.value)} placeholder={t('teach.find_class_hint')} autoComplete="off" />
                    </div>
                )}
                {sections.length === 0 ? (
                    <EmptyState title={t('teach.no_classes_title')}>{t('teach.no_classes_body')}</EmptyState>
                ) : (
                    <>
                        <p className="text-lg font-semibold text-gray-900 dark:text-gray-100">{t('teach.pick_class')}</p>
                        <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" data-testid="class-list">
                            {shown.map((s) => (
                                <li key={s.id}>
                                    <button type="button" onClick={() => setSelectedId(s.id)} data-testid="class-card"
                                        className="flex h-full w-full flex-col gap-1 rounded-2xl border-2 border-gray-300 bg-white p-5 text-left transition hover:border-indigo-500 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 dark:border-gray-600 dark:bg-gray-800">
                                        <span className="text-xl font-bold text-gray-900 dark:text-gray-100">{s.grade} {s.name}</span>
                                        {branchCount > 1 && <span className="text-base text-gray-600 dark:text-gray-400">{s.branch}</span>}
                                        <span className="text-base text-gray-900 dark:text-gray-100">{s.class_teacher ? t('teach.card_ct', { name: s.class_teacher.name }) : <span className="text-amber-800 dark:text-amber-300">{t('teach.ct_none')}</span>}</span>
                                        <span className="mt-1"><StatusBadge tone={s.assignments.length === 0 ? 'warning' : 'info'} label={s.assignments.length === 0 ? t('teach.card_no_subjects') : t('teach.card_subjects', { count: s.assignments.length })} /></span>
                                    </button>
                                </li>
                            ))}
                        </ul>
                        {shown.length === 0 && <p className="text-base text-gray-600 dark:text-gray-400">{t('teach.no_match')}</p>}
                    </>
                )}
            </Page>
        </AuthenticatedLayout>
    );
}
