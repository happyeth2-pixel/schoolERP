import DateInput from '@/Components/DateInput';
import DateText from '@/Components/DateText';
import StudentPhotoField from '@/Components/StudentPhotoField';
import SectionPicker from '@/Components/SectionPicker';
import ServiceChoices, { routeName, usePriceText, useServiceSummary } from '@/Components/ServiceChoices';
import {
    Alert,
    BackLink,
    Button,
    Card,
    ChoiceGroup,
    Disclosure,
    Field,
    FlashMessages,
    Input,
    Page,
    Select,
    StatusBadge,
    useConfirm,
} from '@/Components/ui';
import { todayISO } from '@/calendar';
import { useUnsavedGuard } from '@/useUnsavedGuard';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { DocumentChartBarIcon, ExclamationTriangleIcon, HeartIcon, IdentificationIcon, LifebuoyIcon, PhoneIcon, UserCircleIcon, UserPlusIcon } from '@heroicons/react/24/outline';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

// The student's profile, most important first: who they are, their class, their parents (with a number to call),
// and the two things people come here to do (print the ID card, see the report card). Everything less used is one
// tap away in its own section (nothing removed): personal details, photo, moving class, extra services, withdrawing.
// Every form posts to the same routes with the same fields as before; permissions are unchanged (a read-only
// oversight user sees the same record with no controls).

const cap = (s) => (s ? s.charAt(0).toUpperCase() + s.slice(1) : s);

function PhotoForm({ studentId, photoUrl }) {
    const { t } = useTranslation();
    const [ask, confirmDialog] = useConfirm();
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);

    const upload = (file) => {
        setError(null);
        router.post(route('students.photo', studentId), { photo: file }, {
            forceFormData: true,
            preserveScroll: true,
            onStart: () => setBusy(true),
            onError: (errs) => setError(errs.photo ?? t('photo.err_unreadable')),
            onFinish: () => setBusy(false),
        });
    };

    const remove = async () => {
        const yes = await ask({
            title: t('photo.remove_title'),
            body: <p>{t('photo.remove_body')}</p>,
            confirmLabel: t('photo.remove_confirm'),
            tone: 'danger',
        });
        if (yes) router.delete(route('students.photo.destroy', studentId), { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) });
    };

    return (
        <>
            <StudentPhotoField currentUrl={photoUrl} onUse={upload} onRemove={remove} error={error} busy={busy} />
            {confirmDialog}
        </>
    );
}

// A student's extra services: big grouped cards (Components/ServiceChoices). A tap saves at once through the same
// routes as before; choosing another transport route replaces the first on the server, so a child can never end up
// on two. The optional special price per student is kept out of the way, below.
function CustomPriceRow({ studentId, service }) {
    const { t } = useTranslation();
    const priceText = usePriceText();
    const [value, setValue] = useState(service.custom_amount ?? '');
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);

    const save = (amount) => {
        setBusy(true);
        setError(null);
        router.patch(route('students.optional-services.update', [studentId, service.id]), { custom_amount: amount }, {
            preserveScroll: true,
            onError: (errs) => setError(errs.custom_amount ?? Object.values(errs)[0]),
            onSuccess: () => { if (amount === '') setValue(''); },
            onFinish: () => setBusy(false),
        });
    };

    return (
        <div className="space-y-3 py-4">
            <div>
                <p className="text-base font-semibold text-gray-900 dark:text-gray-100">{service.category === 'transport' ? routeName(service.name) : service.name}</p>
                <p className="text-base text-gray-600 dark:text-gray-400">{t('services.custom_standard', { price: priceText({ ...service, custom_amount: null }) })}</p>
            </div>
            <Field id={`custom_${service.id}`} label={t('services.custom_label')} hint={t('services.custom_hint')} optional error={error}>
                <Input type="number" inputMode="decimal" min="0" step="0.01" value={value} onChange={(e) => setValue(e.target.value)} />
            </Field>
            <div className="flex flex-wrap gap-3">
                <Button variant="secondary" busy={busy} disabled={value === '' || String(value) === String(service.custom_amount ?? '')} onClick={() => save(String(value))}>{t('services.custom_save')}</Button>
                {service.custom_amount != null && <Button variant="secondary" busy={busy} onClick={() => save('')}>{t('services.custom_reset')}</Button>}
            </div>
        </div>
    );
}

function OptionalServicesForm({ studentId, services }) {
    const { t } = useTranslation();
    const [busyId, setBusyId] = useState(null);
    const selectedIds = services.filter((s) => s.enrolled).map((s) => s.id);
    const enrolled = services.filter((s) => s.enrolled);
    const opts = { onFinish: () => setBusyId(null), preserveScroll: true };

    const pick = (s) => {
        setBusyId(s.id);
        router.post(route('students.optional-services.store', studentId), { optional_service_id: s.id }, opts);
    };
    const drop = (s) => {
        setBusyId(s.id);
        router.delete(route('students.optional-services.destroy', [studentId, s.id]), opts);
    };

    if (services.length === 0) {
        return <p className="text-base text-gray-600 dark:text-gray-400">{t('student.services_none')}</p>;
    }

    return (
        <div className="space-y-6">
            <ServiceChoices services={services} selectedIds={selectedIds} onPick={pick} onDrop={drop} busyId={busyId} />
            {enrolled.length > 0 && (
                <Disclosure title={t('services.custom_title')} description={t('services.custom_desc')} defaultOpen={enrolled.some((s) => s.custom_amount != null)}>
                    <div className="divide-y divide-gray-200 dark:divide-gray-700">
                        {enrolled.map((s) => <CustomPriceRow key={`${s.id}:${s.custom_amount ?? ''}`} studentId={studentId} service={s} />)}
                    </div>
                </Disclosure>
            )}
        </div>
    );
}

function AddGuardianForm({ studentId, studentName, onDone, relationships }) {
    const { t } = useTranslation();
    const { data, setData, post, processing, errors, reset, clearErrors } = useForm({
        guardian_mode: 'existing',
        guardian_email: '',
        guardian_name: '',
        guardian_phone: '',
        guardian_relationship: '',
    });
    const set = (k) => (e) => { clearErrors(k); setData(k, e.target.value); };

    const submit = (e) => {
        e.preventDefault();
        post(route('students.guardians.store', studentId), { onSuccess: () => { reset(); onDone(); } });
    };

    return (
        <form onSubmit={submit} className="mt-5 space-y-5 rounded-xl border-2 border-indigo-200 bg-indigo-50/40 p-5 dark:border-indigo-800 dark:bg-indigo-900/10">
            <ChoiceGroup
                layout="segmented"
                label={t('student.parents_title')}
                value={data.guardian_mode}
                onChange={(v) => setData('guardian_mode', v)}
                options={[{ value: 'existing', label: t('enroll.mode_existing') }, { value: 'new', label: t('enroll.mode_new') }]}
            />
            {data.guardian_mode === 'new' && (
                <>
                    <Field id="ng_name" label={t('enroll.parent_name')} error={errors.guardian_name}>
                        <Input value={data.guardian_name} onChange={set('guardian_name')} autoComplete="off" />
                    </Field>
                    <Field id="ng_phone" label={t('enroll.parent_phone')} hint={t('enroll.phone_example')} optional error={errors.guardian_phone}>
                        <Input type="tel" inputMode="tel" value={data.guardian_phone} onChange={set('guardian_phone')} />
                    </Field>
                </>
            )}
            <Field id="ng_email" label={data.guardian_mode === 'new' ? t('enroll.parent_email') : t('enroll.existing_email')} hint={data.guardian_mode === 'new' ? t('enroll.parent_email_hint') : t('enroll.existing_email_hint')} error={errors.guardian_email}>
                <Input type="email" inputMode="email" value={data.guardian_email} onChange={set('guardian_email')} autoComplete="off" />
            </Field>
            <Field id="ng_rel" label={t('enroll.relationship')} error={errors.guardian_relationship}>
                <Select value={data.guardian_relationship} onChange={set('guardian_relationship')}>
                    <option value="">{t('enroll.choose')}</option>
                    {Object.entries(relationships).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                </Select>
            </Field>
            <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                <Button variant="secondary" onClick={onDone}>{t('common.cancel')}</Button>
                <Button type="submit" variant="success" busy={processing} disabled={!data.guardian_email || !data.guardian_relationship}>{t('student.link_button', { name: studentName })}</Button>
            </div>
        </form>
    );
}

function TransferSectionForm({ student, sections, canOverride }) {
    const { t } = useTranslation();
    const [ask, confirmDialog] = useConfirm();
    const { data, setData, post, processing, errors, reset, clearErrors } = useForm({ to_section_id: '', override_capacity: false, override_reason: '' });
    const target = sections.find((s) => String(s.id) === String(data.to_section_id));
    const ready = !!target && (!target.full || (canOverride && data.override_capacity && data.override_reason.trim() !== ''));

    if (sections.length === 0) {
        return <p className="text-base text-gray-600 dark:text-gray-400">{t('student.move_none')}</p>;
    }

    const submit = async (e) => {
        e.preventDefault();
        const yes = await ask({
            title: t('confirm.students_move_section_title'),
            body: <p>{t('confirm.students_move_section')}</p>,
            confirmLabel: t('confirm.students_move_confirm'),
            tone: 'primary',
        });
        if (yes) post(route('students.transfer', student.id), { onSuccess: () => reset() });
    };

    return (
        <form onSubmit={submit} className="space-y-5">
            <SectionPicker
                sections={sections}
                value={data.to_section_id}
                onChange={(id) => { clearErrors('to_section_id'); setData((d) => ({ ...d, to_section_id: id, override_capacity: false, override_reason: '' })); }}
                canOverride={canOverride}
                overrideChecked={data.override_capacity}
                overrideReason={data.override_reason}
                onOverrideChecked={(v) => setData('override_capacity', v)}
                onOverrideReason={(v) => setData('override_reason', v)}
                personName={student.first_name}
                errors={{ section_id: errors.to_section_id, override_reason: errors.override_reason }}
            />
            <Button type="submit" busy={processing} disabled={!ready}>
                {target ? t('student.move_button', { name: student.first_name, class: target.label }) : t('student.move_pick')}
            </Button>
            {confirmDialog}
        </form>
    );
}

function WithdrawForm({ student, minDate }) {
    const { t } = useTranslation();
    const [ask, confirmDialog] = useConfirm();
    const today = todayISO();
    const { data, setData, post, processing, errors } = useForm({ effective_date: today, reason: '' });

    if (student.status === 'withdrawn') {
        return (
            <p className="text-base text-gray-700 dark:text-gray-300">
                {t('student.withdrawn_note')} <DateText value={student.withdrawn_on} />{student.withdrawal_reason ? ` — ${student.withdrawal_reason}` : ''}. {t('student.withdrawn_kept')}
            </p>
        );
    }

    if (student.status !== 'active' && student.status !== 'suspended') {
        return <p className="text-base text-gray-600 dark:text-gray-400">{t('student.not_enrolled')}</p>;
    }

    const submit = async (e) => {
        e.preventDefault();
        const yes = await ask({
            title: t('confirm.students_withdraw_title'),
            body: <p>{t('confirm.students_withdraw')}</p>,
            confirmLabel: t('confirm.students_withdraw_confirm'),
        });
        if (yes) post(route('students.withdraw', student.id));
    };

    return (
        <form onSubmit={submit} className="space-y-5">
            <Field id="effective_date" label={t('student.withdraw_date')} error={errors.effective_date}>
                <DateInput large min={minDate} max={today} value={data.effective_date} onChange={(v) => setData('effective_date', v)} />
            </Field>
            <Field id="reason" label={t('student.withdraw_reason')} error={errors.reason}>
                <Input value={data.reason} onChange={(e) => setData('reason', e.target.value)} />
            </Field>
            <Button type="submit" variant="danger" busy={processing} disabled={data.reason.trim().length < 3}>
                {t('student.withdraw_button', { name: student.first_name })}
            </Button>
            {confirmDialog}
        </form>
    );
}

// Changing a student's details, in three groups so nothing is a wall of fields: MAIN information (who they are),
// MORE details (parents' names, address, phone, previous school) and STATUS. It is still ONE form and one Save that
// sends every field to the same endpoint as before, so no field went away and no rule changed. The Save button says
// which groups were touched, and a change of status asks first, because leaving "Active" takes the student out of
// their class (the server closes their enrolment) and going back needs a free seat.
const MAIN_FIELDS = ['first_name', 'father_name', 'grandfather_name', 'gender', 'date_of_birth'];
const MORE_FIELDS = ['mother_name', 'address', 'emergency_contact', 'previous_school'];

function DetailsForm({ student, canManage }) {
    const { t } = useTranslation();
    const [ask, confirmDialog] = useConfirm();
    const { data, setData, patch, processing, errors, isDirty } = useForm({
        first_name: student.first_name,
        father_name: student.father_name ?? '',
        grandfather_name: student.grandfather_name ?? '',
        mother_name: student.mother_name ?? '',
        gender: student.gender,
        date_of_birth: student.date_of_birth ?? '',
        address: student.address ?? '',
        emergency_contact: student.emergency_contact ?? '',
        previous_school: student.previous_school ?? '',
        status: student.status,
    });
    const set = (k) => (e) => setData(k, e.target.value);
    const initial = {
        first_name: student.first_name, father_name: student.father_name ?? '', grandfather_name: student.grandfather_name ?? '', mother_name: student.mother_name ?? '', gender: student.gender,
        date_of_birth: student.date_of_birth ?? '', address: student.address ?? '', emergency_contact: student.emergency_contact ?? '', previous_school: student.previous_school ?? '', status: student.status,
    };
    const touched = (fields) => fields.some((f) => String(data[f] ?? '') !== String(initial[f] ?? ''));
    const changed = [touched(MAIN_FIELDS) && t('student.g_main'), touched(MORE_FIELDS) && t('student.g_more'), data.status !== student.status && t('student.g_status')].filter(Boolean);
    const errored = (fields) => fields.some((f) => errors[f]);

    useUnsavedGuard(isDirty, () => ask({
        tone: 'primary',
        title: t('student.leave_title'),
        body: <p>{t('student.leave_body', { name: student.first_name })}</p>,
        confirmLabel: t('student.leave_button'),
        cancelLabel: t('student.leave_stay'),
    }));

    const submit = async (e) => {
        e.preventDefault();
        if (data.status !== student.status) {
            const leaving = data.status !== 'active' && student.status === 'active';
            const returning = data.status === 'active' && student.status !== 'active';
            const yes = await ask({
                tone: leaving ? 'danger' : 'primary',
                title: t('student.status_title', { name: student.first_name, from: cap(student.status), to: cap(data.status) }),
                body: <>{leaving && <p>{t('student.status_leaving', { name: student.first_name })}</p>}{returning && <p>{t('student.status_returning', { name: student.first_name })}</p>}{!leaving && !returning && <p>{t('student.status_other')}</p>}<p>{t('student.status_kept')}</p></>,
                confirmLabel: t('student.status_button', { name: student.first_name, to: cap(data.status) }),
            });
            if (!yes) return;
        }
        patch(route('students.update', student.id), { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="space-y-4" data-testid="details-form">
            <fieldset disabled={!canManage} className="space-y-4">
                <Disclosure title={t('student.g_main')} description={t('student.g_main_desc')} defaultOpen={errored(MAIN_FIELDS)} data-testid="group-main">
                    <div className="space-y-5">
                        <Field id="first_name" label={t('enroll.first_name')} error={errors.first_name}><Input value={data.first_name} onChange={set('first_name')} /></Field>
                        <Field id="father_name" label={t('enroll.father_name')} optional error={errors.father_name}><Input value={data.father_name} onChange={set('father_name')} /></Field>
                        <Field id="grandfather_name" label={t('enroll.grandfather_name')} optional error={errors.grandfather_name}><Input value={data.grandfather_name} onChange={set('grandfather_name')} /></Field>
                        <ChoiceGroup heading={t('enroll.gender')} layout="segmented" value={data.gender} onChange={(v) => setData('gender', v)} options={[{ value: 'female', label: t('enroll.female') }, { value: 'male', label: t('enroll.male') }]} />
                        <Field id="date_of_birth" label={t('enroll.date_of_birth')} optional error={errors.date_of_birth} hint={student.age !== null && student.age !== undefined ? t('student.age_note', { count: student.age }) : undefined}>
                            <DateInput large value={data.date_of_birth} max={todayISO()} onChange={(v) => setData('date_of_birth', v)} />
                        </Field>
                    </div>
                </Disclosure>

                <Disclosure title={t('student.g_more')} description={t('student.g_more_desc')} defaultOpen={errored(MORE_FIELDS)} data-testid="group-more">
                    <div className="space-y-5">
                        <Field id="mother_name" label={t('enroll.mother_name')} optional error={errors.mother_name}><Input value={data.mother_name} onChange={set('mother_name')} /></Field>
                        <Field id="address" label={t('enroll.address')} optional error={errors.address}><Input value={data.address} onChange={set('address')} /></Field>
                        <Field id="emergency_contact" label={t('enroll.emergency_contact')} hint={t('enroll.phone_example')} optional error={errors.emergency_contact}><Input type="tel" inputMode="tel" value={data.emergency_contact} onChange={set('emergency_contact')} /></Field>
                        <Field id="previous_school" label={t('enroll.previous_school')} optional error={errors.previous_school}><Input value={data.previous_school} onChange={set('previous_school')} /></Field>
                    </div>
                </Disclosure>

                <Disclosure title={t('student.g_status')} description={t('student.g_status_desc', { status: cap(student.status) })} defaultOpen={Boolean(errors.status)} data-testid="group-status">
                    <div className="space-y-4">
                        <Field id="status" label={t('student.status')} hint={t('student.status_hint')} error={errors.status}>
                            <Select value={data.status} onChange={set('status')}>
                                {['active', 'transferred', 'graduated', 'suspended', 'expelled', 'inactive', ...(student.status === 'withdrawn' ? ['withdrawn'] : [])].map((st) => <option key={st} value={st}>{cap(st)}</option>)}
                            </Select>
                        </Field>
                        <p className="text-base text-gray-700 dark:text-gray-300">{t('student.status_withdraw_hint')}</p>
                    </div>
                </Disclosure>
            </fieldset>

            {canManage && (
                <div className="space-y-2 rounded-2xl border-2 border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800" data-testid="details-save">
                    <p className="text-base text-gray-700 dark:text-gray-300" data-testid="changed-note">{changed.length === 0 ? t('student.nothing_changed') : t('student.changed_in', { groups: changed.join(', ') })}</p>
                    <Button type="submit" variant="success" busy={processing} disabled={changed.length === 0}>{t('student.save_details', { name: student.first_name })}</Button>
                </div>
            )}
            {confirmDialog}
        </form>
    );
}

export default function Edit({ student, guardianRelationships, canOverrideCapacity, canManage, availableServices, transferSections, canManageServices, minWithdrawDate, canViewMedical, canViewDiscipline }) {
    const { t } = useTranslation();
    const { errors: pageErrors } = usePage().props;
    const [addingGuardian, setAddingGuardian] = useState(false);
    const [ask, confirmDialog] = useConfirm();
    const fullName = [student.first_name, student.father_name, student.grandfather_name].filter(Boolean).join(' ');
    const enrolledServices = availableServices.filter((s) => s.enrolled);
    const summariseServices = useServiceSummary();

    const removeGuardian = async (guardian) => {
        const yes = await ask({
            title: t('confirm.students_unlink_guardian_title'),
            body: <p>{t('confirm.students_unlink_guardian', { name: guardian.name })}</p>,
            confirmLabel: t('confirm.students_unlink_confirm'),
        });
        if (yes) router.delete(route('students.guardians.destroy', [student.id, guardian.id]));
    };

    return (
        <AuthenticatedLayout header={<h2 className="truncate text-lg font-semibold text-gray-800 dark:text-gray-200">{t('student.title')}</h2>}>
            <Page
                title={fullName}
                width="default"
                back={<BackLink href={route('students.index')}>{t('enroll.back_to_students')}</BackLink>}
                actions={(canViewMedical || canViewDiscipline) && (
                    <>
                        {canViewMedical && <Button as={Link} href={route('students.medical.show', student.id)} variant="secondary" icon={HeartIcon}>{t('medical.nav_button')}</Button>}
                        {canViewDiscipline && <Button as={Link} href={route('discipline.for-student', student.id)} variant="secondary" icon={ExclamationTriangleIcon}>{t('discipline.nav_button')}</Button>}
                    </>
                )}
            >
                <FlashMessages errorKeys={['locked', 'delete', 'optional_service_id']} />

                {!canManage && <Alert tone="info">{t('student.read_only')}</Alert>}

                {/* ---- who they are, and the two things people come here for */}
                <Card>
                    <div className="flex flex-col gap-5 sm:flex-row sm:items-start">
                        {student.photo_url ? <img src={student.photo_url} alt="" className="h-28 w-28 shrink-0 rounded-2xl object-cover" /> : <UserCircleIcon className="h-28 w-28 shrink-0 text-gray-300 dark:text-gray-600" />}
                        <div className="min-w-0 flex-1">
                            <p className="text-xl font-semibold text-gray-900 dark:text-gray-100">{student.section ?? t('find.no_class')}</p>
                            <p className="mt-1 text-base text-gray-700 dark:text-gray-300">
                                {t('find.id_number', { id: student.student_id_number })} · {t(`enroll.${student.gender}`)}
                                {student.age !== null && student.age !== undefined ? ` · ${t('student.age', { count: student.age })}` : ''}
                            </p>
                            <p className="mt-2"><StatusBadge status={student.status} label={cap(student.status)} /></p>
                            {canManage && student.emergency_contact && (
                                <p className="mt-2 text-base text-gray-700 dark:text-gray-300">{t('student.emergency', { phone: student.emergency_contact })}</p>
                            )}
                        </div>
                    </div>
                    <div className="mt-6 flex flex-col gap-3 sm:flex-row">
                        <Button as="a" href={route('id-card.show', student.id)} icon={IdentificationIcon}>{t('student.print_id')}</Button>
                        <Button as="a" href={route('report-card.show', student.id)} variant="secondary" icon={DocumentChartBarIcon}>{t('student.report_card')}</Button>
                        <Button as="a" href={route('students.desk', student.id)} variant="secondary" icon={LifebuoyIcon} data-testid="help-parent">{t('find.help_parent')}</Button>
                    </div>
                </Card>

                {/* ---- parents: always visible, with a number to call */}
                <Card as="section" aria-labelledby="parents-title">
                    <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                        <h2 id="parents-title" className="text-lg font-semibold text-gray-900 dark:text-gray-100">{t('student.parents_title')}</h2>
                        {canManage && !addingGuardian && student.guardians.length > 0 && (
                            <Button size="md" variant="secondary" icon={UserPlusIcon} onClick={() => setAddingGuardian(true)}>{t('student.link_parent')}</Button>
                        )}
                    </div>

                    {student.guardians.length === 0 ? (
                        <Alert tone="warning" title={t('student.parents_none')} action={canManage && !addingGuardian && <Button icon={UserPlusIcon} onClick={() => setAddingGuardian(true)}>{t('student.link_parent')}</Button>}>
                            {t('student.parents_none_hint')}
                        </Alert>
                    ) : (
                        <ul className="divide-y divide-gray-200 dark:divide-gray-700">
                            {student.guardians.map((g) => (
                                <li key={g.id} className="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between">
                                    <span className="min-w-0">
                                        <span className="block text-lg font-medium text-gray-900 dark:text-gray-100">{g.name} <span className="text-base font-normal text-gray-600 dark:text-gray-400">({g.relationship_label ?? g.relationship})</span></span>
                                        <span className="block break-words text-base text-gray-600 dark:text-gray-400">{g.email}</span>
                                    </span>
                                    <span className="flex flex-wrap gap-2">
                                        {g.phone && <Button as="a" href={`tel:${g.phone.replace(/\s+/g, '')}`} size="md" variant="secondary" icon={PhoneIcon}>{g.phone}</Button>}
                                        {canManage && <Button size="md" variant="quiet" onClick={() => removeGuardian(g)}>{t('student.unlink')}</Button>}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}

                    {canManage && addingGuardian && (
                        <AddGuardianForm relationships={guardianRelationships} studentId={student.id} studentName={student.first_name} onDone={() => setAddingGuardian(false)} />
                    )}
                </Card>

                {/* ---- everything else: one tap away */}
                <div className="space-y-4">
                    <DetailsForm student={student} canManage={canManage} />

                    {canManage && (
                        <Disclosure title={t('student.photo_title')} description={t('student.photo_desc')} defaultOpen={!student.photo_url} data-testid="photo-section">
                            <PhotoForm studentId={student.id} photoUrl={student.photo_url} />
                        </Disclosure>
                    )}

                    <Disclosure title={t('student.move_title')} description={t('student.move_desc', { section: student.section ?? t('find.no_class') })} defaultOpen={!!pageErrors?.to_section_id}>
                        {canManage ? (
                            <TransferSectionForm student={student} sections={transferSections} canOverride={canOverrideCapacity} />
                        ) : (
                            <p className="text-base text-gray-600 dark:text-gray-400">{t('student.read_only_short')}</p>
                        )}
                    </Disclosure>

                    <Disclosure title={t('student.services_title')} description={enrolledServices.length ? summariseServices(availableServices, enrolledServices.map((s) => s.id)) : t('student.services_desc_none')}>
                        {canManageServices ? (
                            <OptionalServicesForm studentId={student.id} services={availableServices} />
                        ) : (
                            <ul className="divide-y divide-gray-200 dark:divide-gray-700">
                                {enrolledServices.map((s) => <li key={s.id} className="py-3 text-base text-gray-800 dark:text-gray-200">{s.name}</li>)}
                                {enrolledServices.length === 0 && <li className="py-3 text-base text-gray-600 dark:text-gray-400">{t('student.services_not_enrolled')}</li>}
                            </ul>
                        )}
                    </Disclosure>

                    {canManage && (
                        <Disclosure tone="danger" title={t('student.withdraw_title')} description={t('student.withdraw_desc')}>
                            <WithdrawForm student={student} minDate={minWithdrawDate} />
                        </Disclosure>
                    )}
                </div>
            </Page>
            {confirmDialog}
        </AuthenticatedLayout>
    );
}
