import Modal from '@/Components/Modal';
import { BackLink, Button, Card, EmptyState, Field, FlashMessages, Input, Page, StatusBadge, useConfirm } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { IdentificationIcon, PlusIcon } from '@heroicons/react/24/outline';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

// Grades (Grade 5) and their sections (Grade 5 B). Each section shows who is in it, how full it is and who its class
// teacher is, so the page answers "is this class set up?" at a glance. Adding a section is the three-step wizard
// (SectionWizard); changing a section is limited to its name and capacity, exactly as before.

function GradeForm({ grade, onDone }) {
    const { t } = useTranslation();
    const isEdit = !!grade;
    const { data, setData, post, patch, processing, errors, reset, clearErrors } = useForm({ name: grade?.name ?? '', sort_order: grade?.sort_order ?? 0 });

    const submit = (e) => {
        e.preventDefault();
        const options = { onSuccess: () => { reset(); onDone(); }, preserveScroll: true };
        if (isEdit) patch(route('grades.update', grade.id), options);
        else post(route('grades.store'), options);
    };

    return (
        <form onSubmit={submit} className="space-y-6 p-6">
            <h2 className="text-2xl font-bold text-stone-900 dark:text-stone-100">{isEdit ? t('struct.grade_edit_title', { name: grade.name }) : t('struct.grade_add_title')}</h2>
            <Field id="grade_name" label={t('struct.grade_name')} hint={t('struct.grade_name_hint')} error={errors.name}>
                <Input value={data.name} onChange={(e) => { clearErrors('name'); setData('name', e.target.value); }} autoFocus autoComplete="off" maxLength={255} />
            </Field>
            <Field id="grade_sort" label={t('struct.grade_order')} hint={t('struct.grade_order_hint')} error={errors.sort_order}>
                <Input inputMode="numeric" value={data.sort_order} onChange={(e) => setData('sort_order', e.target.value)} autoComplete="off" />
            </Field>
            <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                <Button variant="secondary" onClick={onDone}>{t('common.cancel')}</Button>
                <Button type="submit" busy={processing}>{isEdit ? t('struct.grade_save_changes') : t('struct.grade_save')}</Button>
            </div>
        </form>
    );
}

function SectionForm({ grade, section, onDone }) {
    const { t } = useTranslation();
    const { data, setData, patch, processing, errors, clearErrors } = useForm({ name: section.name, capacity: section.capacity ?? '' });

    const submit = (e) => {
        e.preventDefault();
        patch(route('sections.update', section.id), { onSuccess: onDone, preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="space-y-6 p-6" data-testid="section-edit">
            <div>
                <h2 className="text-2xl font-bold text-stone-900 dark:text-stone-100">{t('struct.section_edit_title', { grade: grade.name, name: section.name })}</h2>
                <p className="mt-1 text-base text-stone-600 dark:text-stone-400">{t('struct.section_edit_locked', { branch: section.branch, year: section.academic_year })}</p>
            </div>
            <Field id="edit_section_name" label={t('sect.name_label')} error={errors.name}>
                <Input value={data.name} onChange={(e) => { clearErrors('name'); setData('name', e.target.value); }} autoComplete="off" maxLength={50} />
            </Field>
            <Field id="edit_section_capacity" label={t('sect.capacity_label')} hint={t('struct.capacity_edit_hint', { count: section.students })} optional error={errors.capacity}>
                <Input inputMode="numeric" value={data.capacity} onChange={(e) => { clearErrors('capacity'); setData('capacity', e.target.value); }} autoComplete="off" />
            </Field>
            <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                <Button variant="secondary" onClick={onDone}>{t('common.cancel')}</Button>
                <Button type="submit" busy={processing}>{t('struct.section_save')}</Button>
            </div>
        </form>
    );
}

export default function Index({ grades, canManage, canAssignTeachers, canPrintIdCards }) {
    const { t } = useTranslation();
    const { auth } = usePage().props;
    const isTeacher = auth.role === 'teacher';
    const [ask, confirmDialog] = useConfirm();
    const [gradeForm, setGradeForm] = useState(null);   // null | 'new' | a grade
    const [editing, setEditing] = useState(null);       // { grade, section }

    const removeGrade = async (grade) => {
        const ok = await ask({
            title: t('struct.remove_grade_title', { name: grade.name }),
            body: <p>{t('struct.remove_grade_body')}</p>,
            confirmLabel: t('struct.remove_grade_button', { name: grade.name }),
        });
        if (ok) router.delete(route('grades.destroy', grade.id), { preserveScroll: true });
    };

    const removeSection = async (grade, section) => {
        const ok = await ask({
            title: t('struct.remove_section_title', { grade: grade.name, name: section.name }),
            body: <p>{t('struct.remove_section_body')}</p>,
            confirmLabel: t('struct.remove_section_button', { grade: grade.name, name: section.name }),
        });
        if (ok) router.delete(route('sections.destroy', section.id), { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">{t('struct.page_title')}</h2>}>
            <Page
                title={t('struct.page_title')}
                description={t('struct.page_desc')}
                width="wide"
                back={<BackLink big={isTeacher} href={route('dashboard')}>{t('common.back')}</BackLink>}
                actions={canManage && (
                    <>
                        <Button variant="secondary" icon={PlusIcon} onClick={() => setGradeForm('new')}>{t('struct.add_grade')}</Button>
                        <Button as={Link} href={route('sections.create')} icon={PlusIcon}>{t('struct.add_section')}</Button>
                    </>
                )}
            >
                <FlashMessages errorKeys={['delete']} />

                {grades.length === 0 ? (
                    <EmptyState title={t('struct.empty_title')} action={canManage && <Button icon={PlusIcon} onClick={() => setGradeForm('new')}>{t('struct.add_grade')}</Button>}>{t('struct.empty_body')}</EmptyState>
                ) : (
                    <div className="space-y-5" data-testid="grades">
                        {grades.map((grade) => (
                            <Card key={grade.id} data-testid="grade-card">
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <h2 className="text-xl font-bold text-stone-900 dark:text-stone-100">{grade.name}</h2>
                                        <p className="text-base text-stone-600 dark:text-stone-400">{t('struct.sections_n', { count: grade.sections.length })}</p>
                                    </div>
                                    {canManage && (
                                        <div className="flex flex-wrap gap-2">
                                            <Button size="md" as={Link} href={route('sections.create', { grade: grade.id })} icon={PlusIcon}>{t('struct.add_section_to', { grade: grade.name })}</Button>
                                            <Button size="md" variant="secondary" onClick={() => setGradeForm(grade)}>{t('struct.change_grade')}</Button>
                                            <Button size="md" variant="quiet" onClick={() => removeGrade(grade)}>{t('struct.remove')}</Button>
                                        </div>
                                    )}
                                </div>

                                {grade.sections.length === 0 ? (
                                    <p className="mt-4 rounded-xl bg-stone-50 p-4 text-base text-stone-700 dark:bg-stone-900/40 dark:text-stone-300">{t('struct.no_sections')}</p>
                                ) : (
                                    <ul className="mt-4 divide-y divide-stone-200 dark:divide-stone-700">
                                        {grade.sections.map((s) => (
                                            <li key={s.id} data-testid="section-row" className="flex flex-wrap items-start justify-between gap-3 py-4">
                                                <div className="min-w-0">
                                                    <p className="flex flex-wrap items-center gap-2 text-lg font-semibold text-stone-900 dark:text-stone-100">
                                                        {grade.name} {s.name}
                                                        {s.is_current_year && <StatusBadge tone="success" label={t('struct.current_year')} />}
                                                    </p>
                                                    <p className="text-base text-stone-600 dark:text-stone-400">{s.branch} · {s.academic_year}</p>
                                                    <p className="mt-1 text-base text-stone-900 dark:text-stone-100" data-testid="section-fill">
                                                        {s.capacity ? t('struct.fill', { students: s.students, capacity: s.capacity }) : t('struct.fill_unlimited', { count: s.students })}
                                                    </p>
                                                    <p className="text-base text-stone-900 dark:text-stone-100" data-testid="section-teacher">
                                                        {s.class_teacher ? t('struct.class_teacher', { name: s.class_teacher }) : <span className="text-amber-800 dark:text-amber-300">{t('struct.no_class_teacher')}</span>}
                                                        {canAssignTeachers && <> · <Link href={route('teaching-assignments.index', { section: s.id })} className="font-medium text-brand-700 underline dark:text-brand-300">{s.class_teacher ? t('struct.manage_teachers') : t('struct.choose_teacher')}</Link></>}
                                                    </p>
                                                </div>
                                                <div className="flex flex-wrap gap-2">
                                                    {canPrintIdCards && (
                                                        <Button size="md" variant="secondary" as={Link} href={route('id-card.batch', s.id)} icon={IdentificationIcon}>{t('struct.print_id_cards')}</Button>
                                                    )}
                                                    {canManage && (
                                                        <>
                                                            <Button size="md" variant="secondary" onClick={() => setEditing({ grade, section: s })}>{t('struct.change_section')}</Button>
                                                            <Button size="md" variant="quiet" onClick={() => removeSection(grade, s)}>{t('struct.remove')}</Button>
                                                        </>
                                                    )}
                                                </div>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </Card>
                        ))}
                    </div>
                )}
            </Page>

            <Modal show={gradeForm !== null} onClose={() => setGradeForm(null)}>
                {gradeForm !== null && <GradeForm key={gradeForm === 'new' ? 'new' : gradeForm.id} grade={gradeForm === 'new' ? null : gradeForm} onDone={() => setGradeForm(null)} />}
            </Modal>
            <Modal show={!!editing} onClose={() => setEditing(null)}>
                {editing && <SectionForm key={editing.section.id} grade={editing.grade} section={editing.section} onDone={() => setEditing(null)} />}
            </Modal>
            {confirmDialog}
        </AuthenticatedLayout>
    );
}
