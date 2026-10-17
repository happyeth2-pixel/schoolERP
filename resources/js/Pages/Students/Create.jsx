import DateInput from '@/Components/DateInput';
import SectionPicker from '@/Components/SectionPicker';
import ServiceChoices, { useServiceSummary } from '@/Components/ServiceChoices';
import {
    Alert,
    BackLink,
    Button,
    Card,
    ChoiceGroup,
    Disclosure,
    Field,
    Input,
    Page,
    ReviewList,
    Select,
    WizardShell,
    useWizard,
} from '@/Components/ui';
import { todayISO } from '@/calendar';
import { useCalendar } from '@/useCalendar';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { UserPlusIcon, UsersIcon, ClockIcon } from '@heroicons/react/24/outline';
import StudentPhotoField from '@/Components/StudentPhotoField';
import { useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';

// The receptionist's main task, as a guided conversation: the child's name -> a few facts -> which class ->
// who the parent is -> the parent's details -> a final check. Built on the shared wizard (Components/ui/Wizard.jsx),
// which brought over the good parts of the original wizard (Back keeps answers, server errors jump to the right
// step, Enter never submits half a form). Everything is sent to the same endpoint (StudentController@store) with
// the same fields, so every rule is enforced on the server exactly as before.
//
// Deliberate changes from the first version: no silent defaults on things that end up on a record (gender and the
// parent's relationship must be chosen, they used to start as "male" / "father"); classes are cards showing the
// teacher and free places; the rarely-used details are one tap away inside "More details (optional)".

const STEP_FIELDS = {
    name: ['first_name', 'father_name', 'grandfather_name'],
    about: ['gender', 'date_of_birth', 'mother_name', 'address', 'emergency_contact', 'previous_school'],
    photo: ['photo'],
    class: ['section_id', 'enrollment_date', 'override_capacity', 'override_reason'],
    services: ['service_ids', 'service_ids.0'],
    parent: ['guardian_mode', 'guardian_relationship'],
    details: ['guardian_email', 'guardian_name', 'guardian_phone'],
};

export default function Create({ sections, guardianRelationships, canOverrideCapacity, canManageServices = false, services = [] }) {
    const { t } = useTranslation();
    const summariseServices = useServiceSummary();
    const { format } = useCalendar();

    const { data, setData, post, transform, processing, errors, clearErrors } = useForm({
        first_name: '',
        father_name: '',
        grandfather_name: '',
        mother_name: '',
        gender: '',
        date_of_birth: '',
        address: '',
        emergency_contact: '',
        previous_school: '',
        photo: null,
        enrollment_date: todayISO(),
        section_id: sections.length === 1 ? sections[0].id : '',
        override_capacity: false,
        override_reason: '',
        service_ids: [],
        guardian_mode: '',
        guardian_email: '',
        guardian_name: '',
        guardian_phone: '',
        guardian_relationship: '',
    });
    const set = (key) => (value) => { clearErrors(key); setData(key, value); };
    const field = (key) => (e) => set(key)(e.target.value);

    // "link an existing parent": look the email up as it is typed, and say who was found
    const [lookup, setLookup] = useState({ state: null, guardian: null }); // state: null | checking | found | not_found
    useEffect(() => {
        if (data.guardian_mode !== 'existing' || !data.guardian_email.includes('@')) {
            setLookup({ state: null, guardian: null });
            return undefined;
        }
        let cancelled = false;
        const handle = setTimeout(() => {
            setLookup({ state: 'checking', guardian: null });
            fetch(route('parents.search', { email: data.guardian_email }))
                .then((r) => r.json())
                .then((json) => { if (!cancelled) setLookup({ state: json.guardian ? 'found' : 'not_found', guardian: json.guardian }); });
        }, 400);
        return () => { cancelled = true; clearTimeout(handle); };
    }, [data.guardian_email, data.guardian_mode]);

    const childName = data.first_name.trim();
    const section = sections.find((s) => String(s.id) === String(data.section_id));
    const hasParentStep = data.guardian_mode !== 'none';
    // transport routes and programmes belong to a branch: only the chosen class's branch offers its own
    const branchServices = section ? services.filter((s) => s.branch_id === section.branch_id) : [];
    const hasServicesStep = canManageServices && branchServices.length > 0;
    const pickService = (svc) => setData((d) => ({
        ...d,
        service_ids: [...d.service_ids.filter((id) => svc.category !== 'transport' || services.find((x) => x.id === id)?.category !== 'transport'), svc.id],
    }));
    const dropService = (svc) => setData((d) => ({ ...d, service_ids: d.service_ids.filter((id) => id !== svc.id) }));
    const chosenServiceIds = data.service_ids.filter((id) => branchServices.some((s) => s.id === id));
    const optionalOpen = ['mother_name', 'address', 'emergency_contact', 'previous_school'].some((k) => data[k] || errors[k]);

    const sectionReady =
        !!section && data.enrollment_date !== '' &&
        (!section.full || (canOverrideCapacity && data.override_capacity && data.override_reason.trim() !== ''));
    const parentReady = data.guardian_mode !== '' && (data.guardian_mode === 'none' || data.guardian_relationship !== '');
    const detailsReady = data.guardian_mode === 'existing'
        ? lookup.state === 'found'
        : data.guardian_name.trim() !== '' && data.guardian_email.includes('@');

    const steps = [
        { key: 'name', label: t('enroll.step_name'), description: t('enroll.step_name_desc'), fields: STEP_FIELDS.name, isValid: () => childName !== '', whyBlocked: t('enroll.blocked_name') },
        { key: 'about', label: t('enroll.step_about'), description: t('enroll.step_about_desc'), fields: STEP_FIELDS.about, isValid: () => data.gender !== '', whyBlocked: t('enroll.blocked_gender') },
        { key: 'photo', label: t('enroll.step_photo'), description: t('enroll.step_photo_desc', { name: childName }), fields: STEP_FIELDS.photo, isValid: () => true },
        { key: 'class', label: t('enroll.step_class', { name: childName }), description: t('enroll.step_class_desc'), fields: STEP_FIELDS.class, isValid: () => sectionReady, whyBlocked: section?.full ? t('enroll.blocked_full') : t('enroll.blocked_class') },
        ...(hasServicesStep
            ? [{ key: 'services', label: t('services.step_label'), description: t('services.step_desc', { name: childName }), fields: STEP_FIELDS.services, isValid: () => true }]
            : []),
        { key: 'parent', label: t('enroll.step_parent'), description: t('enroll.step_parent_desc'), fields: STEP_FIELDS.parent, isValid: () => parentReady, whyBlocked: t('enroll.blocked_parent') },
        ...(hasParentStep
            ? [{ key: 'details', label: t('enroll.step_details'), description: data.guardian_mode === 'existing' ? t('enroll.step_details_existing_desc') : t('enroll.step_details_new_desc'), fields: STEP_FIELDS.details, isValid: () => detailsReady, whyBlocked: data.guardian_mode === 'existing' ? t('enroll.blocked_existing') : t('enroll.blocked_new') }]
            : []),
        { key: 'review', label: t('enroll.step_review'), description: t('enroll.step_review_desc', { name: childName }) },
    ];

    const wizard = useWizard({
        steps,
        errors,
        onSubmit: () => {
            transform((d) => ({ ...d, service_ids: hasServicesStep ? chosenServiceIds : [] }));
            post(route('students.store'));
        },
    });
    const indexOf = (key) => steps.findIndex((s) => s.key === key);
    const key = wizard.step.key;

    const relationLabel = guardianRelationships[data.guardian_relationship];
    const parentName = data.guardian_mode === 'existing' ? lookup.guardian?.name : data.guardian_name.trim();
    const fullName = [data.first_name, data.father_name, data.grandfather_name].map((n) => n.trim()).filter(Boolean).join(' ');

    return (
        <AuthenticatedLayout header={<h2 className="truncate text-lg font-semibold text-gray-800 dark:text-gray-200">{t('enroll.page_title')}</h2>}>
            <Page title={t('enroll.page_title')} width="narrow" back={<BackLink href={route('students.index')}>{t('enroll.back_to_students')}</BackLink>}>
                <Card>
                    <WizardShell wizard={wizard} finalLabel={t('enroll.register_button', { name: childName })} processing={processing}>
                        {key === 'name' && (
                            <div className="space-y-5">
                                <Field id="first_name" label={t('enroll.first_name')} error={errors.first_name}>
                                    <Input value={data.first_name} onChange={field('first_name')} autoFocus autoComplete="off" />
                                </Field>
                                <Field id="father_name" label={t('enroll.father_name')} optional error={errors.father_name}>
                                    <Input value={data.father_name} onChange={field('father_name')} autoComplete="off" />
                                </Field>
                                <Field id="grandfather_name" label={t('enroll.grandfather_name')} optional error={errors.grandfather_name}>
                                    <Input value={data.grandfather_name} onChange={field('grandfather_name')} autoComplete="off" />
                                </Field>
                            </div>
                        )}

                        {key === 'about' && (
                            <div className="space-y-5">
                                <div>
                                    <p className="mb-2 text-base font-medium text-gray-900 dark:text-gray-100">{t('enroll.gender')}</p>
                                    <ChoiceGroup
                                        layout="segmented"
                                        label={t('enroll.gender')}
                                        value={data.gender}
                                        onChange={set('gender')}
                                        options={[{ value: 'female', label: t('enroll.female') }, { value: 'male', label: t('enroll.male') }]}
                                    />
                                    {errors.gender && <p className="mt-2 text-sm font-medium text-red-700">{errors.gender}</p>}
                                </div>
                                <Field id="date_of_birth" label={t('enroll.date_of_birth')} hint={t('enroll.dob_hint')} optional error={errors.date_of_birth}>
                                    <DateInput large value={data.date_of_birth} max={todayISO()} onChange={set('date_of_birth')} />
                                </Field>
                                <Disclosure title={t('enroll.more_details')} description={t('enroll.more_details_desc')} defaultOpen={optionalOpen}>
                                    <div className="space-y-5">
                                        <Field id="mother_name" label={t('enroll.mother_name')} optional error={errors.mother_name}>
                                            <Input value={data.mother_name} onChange={field('mother_name')} />
                                        </Field>
                                        <Field id="address" label={t('enroll.address')} optional error={errors.address}>
                                            <Input value={data.address} onChange={field('address')} />
                                        </Field>
                                        <Field id="emergency_contact" label={t('enroll.emergency_contact')} hint={t('enroll.phone_example')} optional error={errors.emergency_contact}>
                                            <Input type="tel" inputMode="tel" value={data.emergency_contact} onChange={field('emergency_contact')} />
                                        </Field>
                                        <Field id="previous_school" label={t('enroll.previous_school')} optional error={errors.previous_school}>
                                            <Input value={data.previous_school} onChange={field('previous_school')} />
                                        </Field>
                                    </div>
                                </Disclosure>
                            </div>
                        )}

                        {key === 'photo' && (
                            <StudentPhotoField accepted={data.photo} onUse={(file) => set('photo')(file)} onRemove={() => set('photo')(null)} error={errors.photo} />
                        )}

                        {key === 'class' && (
                            <div className="space-y-5">
                                <SectionPicker
                                    sections={sections}
                                    value={data.section_id}
                                    onChange={(id) => { clearErrors('section_id'); setData((d) => ({ ...d, section_id: id, override_capacity: false, override_reason: '' })); }}
                                    canOverride={canOverrideCapacity}
                                    overrideChecked={data.override_capacity}
                                    overrideReason={data.override_reason}
                                    onOverrideChecked={set('override_capacity')}
                                    onOverrideReason={set('override_reason')}
                                    personName={childName}
                                    errors={errors}
                                />
                                <Disclosure
                                    title={data.enrollment_date === todayISO() ? t('enroll.starts_today') : t('enroll.starts_on', { date: format(data.enrollment_date) })}
                                    description={t('enroll.change_start_date')}
                                    defaultOpen={!!errors.enrollment_date}
                                >
                                    <Field id="enrollment_date" label={t('enroll.start_date')} error={errors.enrollment_date}>
                                        <DateInput large value={data.enrollment_date} onChange={set('enrollment_date')} />
                                    </Field>
                                </Disclosure>
                            </div>
                        )}

                        {key === 'services' && (
                            <div className="space-y-4">
                                <ServiceChoices services={branchServices} selectedIds={chosenServiceIds} onPick={pickService} onDrop={dropService} />
                                {(errors.service_ids || errors['service_ids.0']) && <Alert tone="danger">{errors.service_ids ?? errors['service_ids.0']}</Alert>}
                            </div>
                        )}

                        {key === 'parent' && (
                            <div className="space-y-5">
                                <ChoiceGroup
                                    label={t('enroll.step_parent')}
                                    value={data.guardian_mode}
                                    onChange={set('guardian_mode')}
                                    options={[
                                        { value: 'new', label: t('enroll.mode_new'), hint: t('enroll.mode_new_hint'), icon: UserPlusIcon },
                                        { value: 'existing', label: t('enroll.mode_existing'), hint: t('enroll.mode_existing_hint'), icon: UsersIcon },
                                        { value: 'none', label: t('enroll.mode_none'), hint: t('enroll.mode_none_hint'), icon: ClockIcon },
                                    ]}
                                />
                                {errors.guardian_mode && <p className="text-sm font-medium text-red-700">{errors.guardian_mode}</p>}
                                {data.guardian_mode && data.guardian_mode !== 'none' && (
                                    <Field id="guardian_relationship" label={t('enroll.relationship')} error={errors.guardian_relationship}>
                                        <Select value={data.guardian_relationship} onChange={field('guardian_relationship')}>
                                            <option value="">{t('enroll.choose')}</option>
                                            {Object.entries(guardianRelationships).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                                        </Select>
                                    </Field>
                                )}
                            </div>
                        )}

                        {key === 'details' && data.guardian_mode === 'new' && (
                            <div className="space-y-5">
                                <Field id="guardian_name" label={t('enroll.parent_name')} error={errors.guardian_name}>
                                    <Input value={data.guardian_name} onChange={field('guardian_name')} autoFocus autoComplete="off" />
                                </Field>
                                <Field id="guardian_phone" label={t('enroll.parent_phone')} hint={t('enroll.phone_example')} optional error={errors.guardian_phone}>
                                    <Input type="tel" inputMode="tel" value={data.guardian_phone} onChange={field('guardian_phone')} />
                                </Field>
                                <Field id="guardian_email" label={t('enroll.parent_email')} hint={t('enroll.parent_email_hint')} error={errors.guardian_email}>
                                    <Input type="email" inputMode="email" value={data.guardian_email} onChange={field('guardian_email')} autoComplete="off" />
                                </Field>
                            </div>
                        )}

                        {key === 'details' && data.guardian_mode === 'existing' && (
                            <div className="space-y-5">
                                <Field id="guardian_email" label={t('enroll.existing_email')} hint={t('enroll.existing_email_hint')} error={errors.guardian_email}>
                                    <Input type="email" inputMode="email" value={data.guardian_email} onChange={field('guardian_email')} autoFocus autoComplete="off" />
                                </Field>
                                {lookup.state === 'checking' && <p className="text-base text-gray-600 dark:text-gray-400">{t('enroll.checking')}</p>}
                                {lookup.state === 'found' && <Alert tone="success" title={t('enroll.found', { name: lookup.guardian.name })}>{t('enroll.found_body', { name: childName })}</Alert>}
                                {lookup.state === 'not_found' && (
                                    <Alert
                                        tone="danger"
                                        title={t('enroll.not_found')}
                                        action={<Button variant="secondary" onClick={() => set('guardian_mode')('new')}>{t('enroll.make_new_instead')}</Button>}
                                    >
                                        {t('enroll.not_found_body')}
                                    </Alert>
                                )}
                            </div>
                        )}

                        {key === 'review' && (
                            <div className="space-y-4">
                                <ReviewList
                                    onEdit={wizard.goTo}
                                    rows={[
                                        { label: t('enroll.review_child'), value: fullName, step: indexOf('name') },
                                        { label: t('enroll.gender'), value: t(`enroll.${data.gender}`), step: indexOf('about') },
                                        { label: t('enroll.date_of_birth'), value: data.date_of_birth ? format(data.date_of_birth) : t('enroll.not_given'), step: indexOf('about') },
                                        { label: t('enroll.review_photo'), value: data.photo ? t('enroll.photo_added') : t('enroll.photo_none'), step: indexOf('photo') },
                                        { label: t('enroll.review_class'), value: section ? `${section.label}${section.full ? ` (${t('enroll.placed_anyway')})` : ''}` : '', step: indexOf('class') },
                                        { label: t('enroll.review_starts'), value: format(data.enrollment_date), step: indexOf('class') },
                                        ...(hasServicesStep
                                            ? [{ label: t('services.review_label'), value: summariseServices(branchServices, chosenServiceIds), step: indexOf('services') }]
                                            : []),
                                        {
                                            label: t('enroll.review_parent'),
                                            value: data.guardian_mode === 'none' ? t('enroll.review_parent_later') : `${parentName ?? ''}${relationLabel ? ` (${relationLabel})` : ''}`,
                                            step: indexOf('parent'),
                                        },
                                        ...(data.guardian_mode === 'new'
                                            ? [{ label: t('enroll.review_parent_contact'), value: [data.guardian_phone, data.guardian_email].filter(Boolean).join(' · '), step: indexOf('details') }]
                                            : []),
                                    ]}
                                />
                                {data.guardian_mode === 'new' && <Alert tone="info">{t('enroll.review_invite')}</Alert>}
                                {data.guardian_mode === 'none' && <Alert tone="warning">{t('enroll.review_no_parent')}</Alert>}
                            </div>
                        )}
                    </WizardShell>
                </Card>
            </Page>
        </AuthenticatedLayout>
    );
}
