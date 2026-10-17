import CredentialsPanel from '@/Components/CredentialsPanel';
import PasswordField from '@/Components/PasswordField';
import { Alert, BackLink, Button, Card, ChoiceGroup, Field, Input, Page, ReviewList, Select, SuccessPanel, WizardShell, useWizard } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { MagnifyingGlassIcon, UserMinusIcon } from '@heroicons/react/24/outline';
import { Link, useForm, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';

// Creating a PARENT account, the same way a staff account is created and entirely optional: a family with no
// account is served exactly as well at the school desk (Find Student > Help a parent). Three short steps:
//   1. Parent          - name and phone number (their sign-in)
//   2. Children        - link 0 or more of the school's own students now (more can be linked later, from a
//                        student's own profile); each one needs how the parent is related to them
//   3. Account         - the phone typed again, a temporary password (made for them, or typed by you), optional email
// The person is given the phone number and temporary password directly and must choose their own password at their
// first sign-in. No student ever gets a login from this: only the parent's own account.

const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const MOBILE = /^(?:\+?251|0)?[79]\d{8}$/;
const phoneClean = (v) => String(v).replace(/[\s\-().]/g, '');
const canonical = (v) => phoneClean(v).replace(/^(?:\+?251|0)/, '');
const blank = () => ({ name: '', phone: '', student_ids: [], relationships: {}, phone_confirmation: '', pw_mode: 'generate', temporary_password: '', email: '' });

export default function Create({ students, guardianRelationships }) {
    const { t } = useTranslation();
    const { flash } = usePage().props;
    const credentials = flash?.credentials;

    const form = useForm(blank());
    const { data, setData, errors, processing, clearErrors } = form;
    const set = (k) => (v) => { clearErrors(k); setData(k, v); };

    const [dismissed, setDismissed] = useState(false);
    useEffect(() => { if (credentials) setDismissed(false); }, [credentials?.password]);

    const [q, setQ] = useState('');
    const chosen = useMemo(() => data.student_ids.map((id) => students.find((s) => s.id === id)).filter(Boolean), [data.student_ids, students]);
    const results = useMemo(() => {
        const typed = q.trim().toLowerCase();
        if (!typed) return [];
        const tokens = typed.split(/\s+/).filter(Boolean);
        return students
            .filter((s) => !data.student_ids.includes(s.id))
            .filter((s) => tokens.every((tk) => `${s.name} ${s.student_id_number} ${s.class ?? ''}`.toLowerCase().includes(tk)))
            .slice(0, 8);
    }, [q, students, data.student_ids]);

    const addChild = (s) => { setQ(''); clearErrors('student_ids'); setData((d) => ({ ...d, student_ids: [...d.student_ids, s.id] })); };
    const removeChild = (id) => setData((d) => { const rel = { ...d.relationships }; delete rel[id]; return { ...d, student_ids: d.student_ids.filter((x) => x !== id), relationships: rel }; });
    const setRelationship = (id, value) => { clearErrors('student_ids'); setData((d) => ({ ...d, relationships: { ...d.relationships, [id]: value } })); };

    const nameOk = data.name.trim() !== '';
    const phoneOk = MOBILE.test(phoneClean(data.phone));
    const childrenOk = data.student_ids.every((id) => data.relationships[id]);
    const confirmOk = phoneOk && data.phone_confirmation.trim() !== '' && canonical(data.phone_confirmation) === canonical(data.phone);
    const emailOk = data.email.trim() === '' || EMAIL.test(data.email.trim());
    const own = data.pw_mode === 'own';
    const pw = data.temporary_password;
    const pwOk = !own || (pw.length >= 8 && /[A-Za-z]/.test(pw) && /\d/.test(pw));

    const steps = [
        { key: 'who', label: t('parents.step_who'), description: t('parents.step_who_desc'), fields: ['name', 'phone'], isValid: () => nameOk && phoneOk, whyBlocked: t('parents.why_who') },
        { key: 'children', label: t('parents.step_children'), description: t('parents.step_children_desc'), fields: ['student_ids'], isValid: () => childrenOk, whyBlocked: t('parents.why_children') },
        {
            key: 'account', label: t('staff.step_account'), description: t('parents.step_account_desc'), fields: ['phone_confirmation', 'temporary_password', 'email'],
            isValid: () => confirmOk && pwOk && emailOk, whyBlocked: !confirmOk ? t('staff.why_confirm') : !pwOk ? t('staff.why_password') : t('staff.err_email'),
        },
    ];

    const wizard = useWizard({
        steps,
        errors,
        onSubmit: () => {
            form.transform((d) => ({
                name: d.name.trim(), phone: d.phone.trim(), phone_confirmation: d.phone_confirmation.trim(), email: d.email.trim(),
                student_ids: d.student_ids, relationships: d.relationships,
                temporary_password: d.pw_mode === 'own' ? d.temporary_password : null,
            }));
            form.post(route('parents.store'), { preserveScroll: true });
        },
    });
    const key = wizard.step.key;
    const stepOf = (k) => steps.findIndex((s) => s.key === k);

    const layoutHeader = <h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">{t('parents.title_add')}</h2>;
    const back = <BackLink href={route('parents.index')}>{t('parents.back_to_parents')}</BackLink>;

    // ------------------------------------------------------------------ created
    if (credentials && !dismissed) {
        return (
            <AuthenticatedLayout header={layoutHeader}>
                <Page title={t('parents.title_add')} width="default">
                    <SuccessPanel
                        title={t('cred.created_title')}
                        details={<CredentialsPanel credentials={credentials} />}
                        actions={
                            <div className="flex w-full flex-col gap-3">
                                <Button variant="secondary" fullWidth onClick={() => { setDismissed(true); form.clearErrors(); form.setData(blank()); wizard.reset(); }}>{t('parents.add_another')}</Button>
                                <Button as={Link} href={route('parents.index')} variant="quiet" fullWidth>{t('parents.see_parents')}</Button>
                            </div>
                        }
                    />
                </Page>
            </AuthenticatedLayout>
        );
    }

    return (
        <AuthenticatedLayout header={layoutHeader}>
            <Page title={t('parents.title_add')} description={t('parents.desc_add')} width="default" back={back}>
                <Card>
                    <WizardShell wizard={wizard} finalLabel={t('staff.create_button')} processing={processing}>
                        {key === 'who' && (
                            <div className="space-y-6">
                                <Field id="parent_name" label={t('parents.name')} hint={t('parents.name_hint')} error={errors.name}>
                                    <Input value={data.name} onChange={(e) => set('name')(e.target.value)} autoFocus autoComplete="off" maxLength={255} />
                                </Field>
                                <Field id="parent_phone" label={t('staff.phone')} hint={t('parents.phone_hint')} error={data.phone !== '' && !phoneOk ? t('staff.err_phone') : errors.phone}>
                                    <Input type="tel" inputMode="tel" value={data.phone} onChange={(e) => set('phone')(e.target.value)} autoComplete="off" maxLength={50} />
                                </Field>
                            </div>
                        )}

                        {key === 'children' && (
                            <div className="space-y-6">
                                <p className="text-base text-gray-700 dark:text-gray-300">{t('parents.children_intro')}</p>

                                {chosen.length > 0 && (
                                    <ul className="space-y-3" data-testid="chosen-children">
                                        {chosen.map((s) => (
                                            <li key={s.id} className="rounded-2xl border-2 border-gray-200 p-4 dark:border-gray-700" data-testid="chosen-child">
                                                <div className="flex flex-wrap items-start justify-between gap-3">
                                                    <div>
                                                        <p className="text-lg font-semibold text-gray-900 dark:text-gray-100">{s.name}</p>
                                                        <p className="text-sm text-gray-600 dark:text-gray-400">{s.class ?? t('find.no_class')} · {t('find.id_number', { id: s.student_id_number })}</p>
                                                    </div>
                                                    <Button variant="quiet" size="md" icon={UserMinusIcon} onClick={() => removeChild(s.id)} data-testid="remove-child">{t('parents.remove_child')}</Button>
                                                </div>
                                                <div className="mt-3 max-w-xs">
                                                    <Field id={`relationship_${s.id}`} label={t('enroll.relationship')}>
                                                        <Select value={data.relationships[s.id] ?? ''} onChange={(e) => setRelationship(s.id, e.target.value)}>
                                                            <option value="">{t('enroll.choose')}</option>
                                                            {Object.entries(guardianRelationships).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                                                        </Select>
                                                    </Field>
                                                </div>
                                            </li>
                                        ))}
                                    </ul>
                                )}

                                <div>
                                    <label htmlFor="child_search" className="mb-2 block text-base font-semibold text-gray-900 dark:text-gray-100">{t('parents.add_child_label')}</label>
                                    <div className="relative">
                                        <MagnifyingGlassIcon className="pointer-events-none absolute left-4 top-1/2 h-6 w-6 -translate-y-1/2 text-gray-500" aria-hidden="true" />
                                        <Input id="child_search" type="search" inputMode="search" autoComplete="off" placeholder={t('parents.add_child_placeholder')} value={q} onChange={(e) => setQ(e.target.value)} className="pl-12" />
                                    </div>
                                    {q.trim() !== '' && (
                                        results.length === 0 ? (
                                            <p className="mt-2 text-base text-gray-600 dark:text-gray-400" data-testid="no-child-results">{t('parents.no_child_results')}</p>
                                        ) : (
                                            <ul className="mt-2 space-y-2" data-testid="child-results">
                                                {results.map((s) => (
                                                    <li key={s.id}>
                                                        <button type="button" onClick={() => addChild(s)} className="flex min-h-[56px] w-full items-center justify-between gap-3 rounded-xl border-2 border-gray-300 bg-white px-4 py-2 text-left hover:border-indigo-400 dark:border-gray-600 dark:bg-gray-800" data-testid="child-result">
                                                            <span>
                                                                <span className="block text-base font-semibold text-gray-900 dark:text-gray-100">{s.name}</span>
                                                                <span className="block text-sm text-gray-600 dark:text-gray-400">{s.class ?? t('find.no_class')} · {t('find.id_number', { id: s.student_id_number })}</span>
                                                            </span>
                                                            <span className="text-sm font-semibold text-indigo-700 dark:text-indigo-300">{t('parents.add_child_button')}</span>
                                                        </button>
                                                    </li>
                                                ))}
                                            </ul>
                                        )
                                    )}
                                </div>
                                <p className="text-base text-gray-600 dark:text-gray-400">{t('parents.children_skip_hint')}</p>
                            </div>
                        )}

                        {key === 'account' && (
                            <div className="space-y-6">
                                <div className="rounded-2xl border-2 border-indigo-300 bg-indigo-50 p-5 dark:border-indigo-700 dark:bg-indigo-900/20" data-testid="headline">
                                    <p className="text-2xl font-bold text-gray-900 dark:text-gray-100">{data.name.trim()}</p>
                                    <p className="mt-1 text-lg text-gray-800 dark:text-gray-200">{chosen.length > 0 ? chosen.map((s) => s.name).join(', ') : t('parents.no_children_yet')}</p>
                                </div>
                                <ReviewList
                                    onEdit={wizard.goTo}
                                    rows={[
                                        { label: t('staff.r_name'), value: data.name.trim(), step: stepOf('who') },
                                        { label: t('staff.r_login'), value: data.phone.trim(), step: stepOf('who') },
                                        { label: t('parents.r_children'), value: chosen.length > 0 ? chosen.map((s) => `${s.name} (${guardianRelationships[data.relationships[s.id]] ?? ''})`).join(', ') : t('parents.no_children_yet'), step: stepOf('children') },
                                    ]}
                                />

                                <Field id="parent_phone_confirmation" label={t('staff.confirm_phone')} hint={t('staff.confirm_phone_hint')} error={data.phone_confirmation !== '' && !confirmOk ? t('staff.err_confirm') : errors.phone_confirmation}>
                                    <Input type="tel" inputMode="tel" value={data.phone_confirmation} onChange={(e) => set('phone_confirmation')(e.target.value)} autoComplete="off" maxLength={50} />
                                </Field>

                                <ChoiceGroup
                                    heading={t('staff.pw_question')}
                                    value={data.pw_mode}
                                    onChange={set('pw_mode')}
                                    options={[
                                        { value: 'generate', label: t('staff.pw_generate'), hint: t('staff.pw_generate_hint') },
                                        { value: 'own', label: t('staff.pw_own'), hint: t('staff.pw_own_hint') },
                                    ]}
                                />
                                {own && (
                                    <PasswordField id="parent_temp_password" label={t('staff.pw_label')} hint={t('staff.pw_hint')} error={pw !== '' && !pwOk ? t('staff.err_password') : errors.temporary_password} value={pw} onChange={set('temporary_password')} autoComplete="new-password" />
                                )}

                                <Field id="parent_email" label={t('staff.email')} hint={t('parents.email_hint')} optional error={data.email !== '' && !emailOk ? t('staff.err_email') : errors.email}>
                                    <Input type="email" inputMode="email" value={data.email} onChange={(e) => set('email')(e.target.value)} autoComplete="off" maxLength={255} />
                                </Field>

                                <Alert tone="info" title={t('staff.give_title')}>{t('staff.give_body')}</Alert>
                            </div>
                        )}
                    </WizardShell>
                </Card>
            </Page>
        </AuthenticatedLayout>
    );
}
