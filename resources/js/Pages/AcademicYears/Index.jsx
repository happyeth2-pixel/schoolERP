import DateInput from '@/Components/DateInput';
import DateText from '@/Components/DateText';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import { BackLink } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

function YearForm({ year, onDone }) {
    const { t } = useTranslation();
    const isEdit = !!year;
    const { data, setData, post, patch, processing, errors, reset } = useForm({
        name: year?.name ?? '',
        start_date: year?.start_date ?? '',
        end_date: year?.end_date ?? '',
    });

    const submit = (e) => {
        e.preventDefault();
        const options = { onSuccess: () => { reset(); onDone(); } };
        if (isEdit) {
            patch(route('academic-years.update', year.id), options);
        } else {
            post(route('academic-years.store'), options);
        }
    };

    return (
        <form onSubmit={submit} className="space-y-4 p-6">
            <h2 className="text-lg font-medium text-stone-900 dark:text-stone-100">
                {isEdit ? t('academic_years.edit_year_title') : t('academic_years.add_year_title')}
            </h2>
            <div>
                <InputLabel htmlFor="name" value={t('academic_years.name_label')} />
                <TextInput id="name" className="mt-1 block w-full" value={data.name} onChange={(e) => setData('name', e.target.value)} />
                <InputError message={errors.name} className="mt-1" />
            </div>
            <div className="grid grid-cols-2 gap-4">
                <div>
                    <InputLabel htmlFor="start_date" value={t('academic_years.start_date_label')} />
                    <DateInput id="start_date" className="mt-1" value={data.start_date} onChange={(v) => setData('start_date', v)} />
                    <InputError message={errors.start_date} className="mt-1" />
                </div>
                <div>
                    <InputLabel htmlFor="end_date" value={t('academic_years.end_date_label')} />
                    <DateInput id="end_date" className="mt-1" value={data.end_date} onChange={(v) => setData('end_date', v)} />
                    <InputError message={errors.end_date} className="mt-1" />
                </div>
            </div>
            <div className="flex justify-end gap-3">
                <SecondaryButton type="button" onClick={onDone}>{t('academic_years.cancel_button')}</SecondaryButton>
                <PrimaryButton disabled={processing}>{t('academic_years.save_button')}</PrimaryButton>
            </div>
        </form>
    );
}

function TermForm({ academicYearId, term, onDone }) {
    const { t } = useTranslation();
    const isEdit = !!term;
    const { data, setData, post, patch, processing, errors, reset } = useForm({
        name: term?.name ?? '',
        start_date: term?.start_date ?? '',
        end_date: term?.end_date ?? '',
    });

    const submit = (e) => {
        e.preventDefault();
        const options = { onSuccess: () => { reset(); onDone(); } };
        if (isEdit) {
            patch(route('terms.update', term.id), options);
        } else {
            post(route('terms.store', academicYearId), options);
        }
    };

    return (
        <form onSubmit={submit} className="space-y-4 p-6">
            <h2 className="text-lg font-medium text-stone-900 dark:text-stone-100">
                {isEdit ? t('academic_years.edit_term_title') : t('academic_years.add_term_title')}
            </h2>
            <div>
                <InputLabel htmlFor="term_name" value={t('academic_years.term_name_label')} />
                <TextInput id="term_name" className="mt-1 block w-full" value={data.name} onChange={(e) => setData('name', e.target.value)} />
                <InputError message={errors.name} className="mt-1" />
            </div>
            <div className="grid grid-cols-2 gap-4">
                <div>
                    <InputLabel htmlFor="term_start" value={t('academic_years.start_date_label')} />
                    <DateInput id="term_start" className="mt-1" value={data.start_date} onChange={(v) => setData('start_date', v)} />
                    <InputError message={errors.start_date} className="mt-1" />
                </div>
                <div>
                    <InputLabel htmlFor="term_end" value={t('academic_years.end_date_label')} />
                    <DateInput id="term_end" className="mt-1" value={data.end_date} onChange={(v) => setData('end_date', v)} />
                    <InputError message={errors.end_date} className="mt-1" />
                </div>
            </div>
            <div className="flex justify-end gap-3">
                <SecondaryButton type="button" onClick={onDone}>{t('academic_years.cancel_button')}</SecondaryButton>
                <PrimaryButton disabled={processing}>{t('academic_years.save_button')}</PrimaryButton>
            </div>
        </form>
    );
}

function MakeCurrentForm({ year, current, onDone }) {
    const { t } = useTranslation();
    const { data, setData, post, processing, errors } = useForm({ confirm_name: '', reason: '' });

    const submit = (e) => {
        e.preventDefault();
        post(route('academic-years.make-current', year.id), { preserveScroll: true, onSuccess: onDone });
    };

    return (
        <form onSubmit={submit} className="space-y-4 p-6">
            <h2 className="text-lg font-medium text-stone-900 dark:text-stone-100">{t('academic_years.make_current_title', { name: year.name })}</h2>
            <div className="space-y-2 rounded-md bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-900/20 dark:text-amber-200">
                <p><strong>{t('academic_years.make_current_changes_label')}</strong> {t('academic_years.make_current_changes_body', { name: year.name })}</p>
                <p><strong>{t('academic_years.make_current_nochange_label')}</strong> {t('academic_years.make_current_nochange_body')}{current ? t('academic_years.make_current_keeps', { name: current.name, invoices: current.invoices_count, enrollments: current.active_enrollments }) : ''}.</p>
                <p>{t('academic_years.make_current_so_far', { name: year.name, sections: year.sections_count, invoices: year.invoices_count, enrollments: year.active_enrollments })}</p>
            </div>
            <div>
                <InputLabel htmlFor="reason" value={t('academic_years.reason_label')} />
                <TextInput id="reason" className="mt-1 block w-full" value={data.reason} onChange={(e) => setData('reason', e.target.value)} />
                <InputError message={errors.reason} className="mt-1" />
            </div>
            <div>
                <InputLabel htmlFor="confirm_name" value={t('academic_years.confirm_name_label', { name: year.name })} />
                <TextInput id="confirm_name" className="mt-1 block w-full" value={data.confirm_name} onChange={(e) => setData('confirm_name', e.target.value)} autoComplete="off" />
                <InputError message={errors.confirm_name} className="mt-1" />
            </div>
            <div className="flex justify-end gap-3">
                <SecondaryButton type="button" onClick={onDone}>{t('academic_years.cancel_button')}</SecondaryButton>
                <PrimaryButton disabled={processing || data.confirm_name.trim() !== year.name}>{t('academic_years.switch_button')}</PrimaryButton>
            </div>
        </form>
    );
}

export default function Index({ academicYears, canSwitchYear }) {
    const { t } = useTranslation();
    const { flash, auth } = usePage().props;
    const isTeacher = auth.role === 'teacher';
    const [showCreate, setShowCreate] = useState(false);
    const [editingYear, setEditingYear] = useState(null);
    const [termFormFor, setTermFormFor] = useState(null);
    const [switchingTo, setSwitchingTo] = useState(null);
    const currentYear = academicYears.find((y) => y.is_current);

    return (
        <AuthenticatedLayout
            header={<h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">{t('academic_years.header')}</h2>}
        >
            <Head title={t('academic_years.page_title')} />

            <div className="py-8">
                <div className="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
                    <BackLink big={isTeacher} href={route('dashboard')}>{t('common.back')}</BackLink>
                    {flash?.success && (
                        <div className="mb-4 rounded-md bg-green-50 px-4 py-3 text-sm text-green-700 dark:bg-green-900/30 dark:text-green-300">
                            {flash.success}
                        </div>
                    )}

                    <div className="mb-4 flex justify-end">
                        <PrimaryButton onClick={() => setShowCreate(true)}>{t('academic_years.add_year_button')}</PrimaryButton>
                    </div>

                    <div className="space-y-4">
                        {academicYears.map((year) => (
                            <div key={year.id} className="rounded-lg bg-white p-5 shadow-sm dark:bg-stone-800">
                                <div className="flex items-center justify-between">
                                    <div>
                                        <p className="font-medium text-stone-900 dark:text-stone-100">
                                            {year.name}
                                            {year.is_current && (
                                                <span className="ml-2 rounded-full bg-brand-100 px-2 py-0.5 text-xs font-medium text-brand-700 dark:bg-brand-900/40 dark:text-brand-300">
                                                    {t('academic_years.current_badge')}
                                                </span>
                                            )}
                                        </p>
                                        <p className="text-sm text-stone-500"><DateText value={year.start_date} /> → <DateText value={year.end_date} /> · {t('academic_years.terms_count', { count: year.terms_count })}</p>
                                    </div>
                                    <div className="space-x-3 text-sm">
                                        <button className="text-brand-600 hover:underline dark:text-brand-400" onClick={() => setEditingYear(year)}>{t('academic_years.edit_link')}</button>
                                        <button className="text-brand-600 hover:underline dark:text-brand-400" onClick={() => setTermFormFor(year.id)}>{t('academic_years.add_term_link')}</button>
                                        {canSwitchYear && !year.is_current && (
                                            <button className="text-amber-700 hover:underline dark:text-amber-400" onClick={() => setSwitchingTo(year)}>{t('academic_years.make_current_link')}</button>
                                        )}
                                    </div>
                                </div>
                            </div>
                        ))}
                        {academicYears.length === 0 && (
                            <p className="text-center text-sm text-stone-400">{t('academic_years.none_yet')}</p>
                        )}
                    </div>
                </div>
            </div>

            <Modal show={showCreate} onClose={() => setShowCreate(false)}>
                <YearForm onDone={() => setShowCreate(false)} />
            </Modal>
            <Modal show={!!editingYear} onClose={() => setEditingYear(null)}>
                {editingYear && <YearForm year={editingYear} onDone={() => setEditingYear(null)} />}
            </Modal>
            <Modal show={!!switchingTo} onClose={() => setSwitchingTo(null)}>
                {switchingTo && <MakeCurrentForm year={switchingTo} current={currentYear} onDone={() => setSwitchingTo(null)} />}
            </Modal>
            <Modal show={!!termFormFor} onClose={() => setTermFormFor(null)}>
                {termFormFor && <TermForm academicYearId={termFormFor} onDone={() => setTermFormFor(null)} />}
            </Modal>
        </AuthenticatedLayout>
    );
}
