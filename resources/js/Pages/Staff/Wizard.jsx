import CredentialsPanel from '@/Components/CredentialsPanel';
import PasswordField from '@/Components/PasswordField';
import { PermissionPicker, PermissionSummary, ROLE_ORDER, TitleVersusAccess } from '@/Components/StaffParts';
import { Alert, BackLink, Button, Card, ChoiceGroup, Disclosure, Field, Input, Page, ReviewList, Select, SuccessPanel, WizardShell, useWizard } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Link, useForm, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';

// Creating a staff account as an administrator, in three short steps:
//   1. Staff member  - name, phone number, job title (a label only)
//   2. School access - role, branch, permissions and, for a teacher, the class they look after
//   3. Account       - a temporary password (made for them, or typed by you), Create Account
// The administrator gives the phone number and temporary password to the person directly; the person must choose their
// own password at the first sign-in. Nothing here decides access: the server still refuses roles the person adding
// cannot give, clamps permissions to what they hold themselves, and scopes the branch.

// 0911..., 0711..., 911..., +251911..., 251911... (spaces and dashes allowed): what the server accepts as a mobile number
const MOBILE = /^(?:\+?251|0)?[79]\d{8}$/;
const phoneClean = (v) => String(v).replace(/[\s\-().]/g, '');
const canonical = (v) => phoneClean(v).replace(/^(?:\+?251|0)/, '');
const ACROSS_BRANCHES = ['owner', 'auditor'];
const blank = (branches) => ({
    name: '', phone: '', title: '', role: '', branch_id: branches.length === 1 ? String(branches[0].id) : '', permissions: [],
    class_teacher_section_id: '', pw_mode: 'generate', temporary_password: '',
});

export default function Wizard({ assignableRoles, permissionGroups, tierDefaults, myPermissions, branches, canAssignClass, classesWithoutTeacher }) {
    const { t } = useTranslation();
    const { flash } = usePage().props;
    const credentials = flash?.credentials;

    const form = useForm(blank(branches));
    const { data, setData, errors, processing, clearErrors } = form;
    const set = (k) => (v) => { clearErrors(k); setData(k, v); };

    const [dismissed, setDismissed] = useState(false);
    useEffect(() => { if (credentials) setDismissed(false); }, [credentials?.password]);

    const roles = ROLE_ORDER.filter((r) => assignableRoles.includes(r));
    const customises = data.role === 'branch_admin';
    const acrossBranches = ACROSS_BRANCHES.includes(data.role);
    const needsBranch = data.role !== '' && !acrossBranches;
    const usual = (r) => (tierDefaults[r] ?? []).filter((p) => myPermissions.includes(p));
    const effective = customises ? data.permissions : (tierDefaults[data.role] ?? []);
    const branch = branches.find((b) => String(b.id) === data.branch_id);
    const classes = (classesWithoutTeacher ?? []).filter((c) => String(c.branch_id) === data.branch_id);
    const askClass = data.role === 'teacher' && canAssignClass && data.branch_id !== '';
    const chosenClass = classes.find((c) => String(c.id) === String(data.class_teacher_section_id));

    const chooseRole = (r) => {
        clearErrors('role');
        setData((d) => ({ ...d, role: r, permissions: r === 'branch_admin' ? usual(r) : [], class_teacher_section_id: r === 'teacher' ? d.class_teacher_section_id : '' }));
    };

    const nameOk = data.name.trim() !== '';
    const phoneOk = MOBILE.test(phoneClean(data.phone));
    const branchOk = !needsBranch || data.branch_id !== '';
    const permsOk = !customises || data.permissions.length > 0;
    const own = data.pw_mode === 'own';
    const pw = data.temporary_password;
    const pwOk = !own || (pw.length >= 8 && /[A-Za-z]/.test(pw) && /\d/.test(pw));

    const steps = [
        {
            key: 'who', label: t('staff.step_who'), description: t('staff.step_who_desc'), fields: ['name', 'phone', 'title'],
            isValid: () => nameOk && phoneOk, whyBlocked: t('staff.why_who'),
        },
        {
            key: 'access', label: t('staff.step_access'), description: t('staff.step_access_desc'), fields: ['role', 'branch_id', 'permissions', 'class_teacher_section_id'],
            isValid: () => data.role !== '' && branchOk && permsOk, whyBlocked: data.role === '' ? t('staff.why_role') : !branchOk ? t('staff.why_branch') : t('staff.why_perms'),
        },
        {
            key: 'account', label: t('staff.step_account'), description: t('staff.step_account_desc'), fields: ['temporary_password'],
            isValid: () => pwOk, whyBlocked: t('staff.why_password'),
        },
    ];

    const wizard = useWizard({
        steps,
        errors,
        onSubmit: () => {
            form.transform((d) => ({
                name: d.name.trim(), title: d.title.trim() || null, phone: d.phone.trim(), role: d.role,
                branch_id: ACROSS_BRANCHES.includes(d.role) ? null : d.branch_id || null,
                class_teacher_section_id: d.role === 'teacher' && d.class_teacher_section_id ? d.class_teacher_section_id : null,
                temporary_password: d.pw_mode === 'own' ? d.temporary_password : null,
                ...(d.role === 'branch_admin' ? { permissions: d.permissions } : {}),
            }));
            form.post(route('staff.store'), { preserveScroll: true });
        },
    });
    const key = wizard.step.key;
    const stepOf = (k) => steps.findIndex((s) => s.key === k);

    const layoutHeader = <h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">{t('staff.title_add')}</h2>;
    const back = <BackLink href={route('staff.index')}>{t('staff.back_to_staff')}</BackLink>;

    // ------------------------------------------------------------------ created
    if (credentials && !dismissed) {
        return (
            <AuthenticatedLayout header={layoutHeader}>
                <Page title={t('staff.title_add')} width="default">
                    <SuccessPanel
                        title={t('cred.created_title')}
                        details={<CredentialsPanel credentials={credentials} />}
                        actions={
                            <div className="flex w-full flex-col gap-3">
                                <Button variant="secondary" fullWidth onClick={() => { setDismissed(true); form.clearErrors(); form.setData(blank(branches)); wizard.reset(); }}>{t('staff.add_another')}</Button>
                                <Button as={Link} href={route('staff.index')} variant="quiet" fullWidth>{t('staff.see_staff')}</Button>
                            </div>
                        }
                    />
                </Page>
            </AuthenticatedLayout>
        );
    }

    return (
        <AuthenticatedLayout header={layoutHeader}>
            <Page title={t('staff.title_add')} description={t('staff.desc_add')} width="default" back={back}>
                <Card>
                    <WizardShell wizard={wizard} finalLabel={t('staff.create_button')} processing={processing}>
                        {key === 'who' && (
                            <div className="space-y-6">
                                <Field id="staff_name" label={t('staff.name')} hint={t('staff.name_hint')} error={errors.name}>
                                    <Input value={data.name} onChange={(e) => set('name')(e.target.value)} autoFocus autoComplete="off" maxLength={255} />
                                </Field>
                                <Field id="staff_phone" label={t('staff.phone')} hint={t('staff.phone_hint')} error={data.phone !== '' && !phoneOk ? t('staff.err_phone') : errors.phone}>
                                    <Input type="tel" inputMode="tel" value={data.phone} onChange={(e) => set('phone')(e.target.value)} autoComplete="off" maxLength={50} />
                                </Field>
                                <Field id="staff_title" label={t('staff.title_label')} hint={t('staff.title_hint')} optional error={errors.title}>
                                    <Input value={data.title} onChange={(e) => set('title')(e.target.value)} autoComplete="off" maxLength={255} />
                                </Field>
                                <TitleVersusAccess compact />
                            </div>
                        )}

                        {key === 'access' && (
                            <div className="space-y-6">
                                <TitleVersusAccess compact />
                                <div>
                                    <ChoiceGroup
                                        heading={t('staff.role_label')}
                                        value={data.role}
                                        onChange={chooseRole}
                                        options={roles.map((r) => ({ value: r, label: t(`staff.role_${r}`), hint: t(`staff.role_${r}_hint`) }))}
                                    />
                                    {errors.role && <p className="mt-2 text-sm font-medium text-red-700">{errors.role}</p>}
                                </div>

                                {data.role === 'owner' && <Alert tone="warning" title={t('staff.owner_warn_title')}>{t('staff.owner_warn_body')}</Alert>}

                                {acrossBranches && <p className="rounded-xl bg-stone-50 p-4 text-base text-stone-800 dark:bg-stone-900/40 dark:text-stone-200" data-testid="all-branches">{t('staff.all_branches_note')}</p>}

                                {needsBranch && branches.length > 1 && (
                                    <div>
                                        <ChoiceGroup heading={t('staff.branch_label')} value={data.branch_id} onChange={(v) => setData((d) => ({ ...d, branch_id: v, class_teacher_section_id: '' }))} options={branches.map((b) => ({ value: String(b.id), label: b.name }))} />
                                        {errors.branch_id && <p className="mt-2 text-sm font-medium text-red-700">{errors.branch_id}</p>}
                                    </div>
                                )}
                                {needsBranch && branches.length === 1 && (
                                    <p className="rounded-xl bg-stone-50 p-4 text-base text-stone-800 dark:bg-stone-900/40 dark:text-stone-200" data-testid="fixed-branch">{t('staff.fixed_branch', { branch: branches[0].name })}</p>
                                )}

                                {customises && (
                                    <div className="space-y-3" data-testid="custom-permissions">
                                        <div>
                                            <h4 className="text-lg font-semibold text-stone-900 dark:text-stone-100">{t('staff.perm_head')}</h4>
                                            <p className="text-base text-stone-600 dark:text-stone-400">{t('staff.perm_intro')}</p>
                                        </div>
                                        <PermissionPicker groups={permissionGroups} value={data.permissions} onChange={set('permissions')} myPermissions={myPermissions} defaults={tierDefaults.branch_admin} />
                                        {errors.permissions && <p className="text-sm font-medium text-red-700">{errors.permissions}</p>}
                                    </div>
                                )}
                                {data.role && !customises && (
                                    <div className="space-y-2" data-testid="fixed-permissions">
                                        <h4 className="text-lg font-semibold text-stone-900 dark:text-stone-100">{t('staff.fixed_head', { role: t(`staff.role_${data.role}`) })}</h4>
                                        <p className="text-base text-stone-600 dark:text-stone-400">{t('staff.fixed_intro')}</p>
                                        <PermissionSummary names={effective} />
                                    </div>
                                )}

                                {askClass && (
                                    <div data-testid="class-question">
                                        <Field id="staff_class" label={t('staff.class_label')} hint={classes.length ? t('staff.class_hint') : t('staff.class_none')} optional error={errors.class_teacher_section_id}>
                                            <Select value={data.class_teacher_section_id} onChange={(e) => set('class_teacher_section_id')(e.target.value)} disabled={classes.length === 0}>
                                                <option value="">{t('staff.class_no_one')}</option>
                                                {classes.map((c) => <option key={c.id} value={c.id}>{c.label}</option>)}
                                            </Select>
                                        </Field>
                                    </div>
                                )}
                            </div>
                        )}

                        {key === 'account' && (
                            <div className="space-y-6">
                                <div className="rounded-2xl border-2 border-brand-300 bg-brand-50 p-5 dark:border-brand-700 dark:bg-brand-900/20" data-testid="headline">
                                    <p className="text-2xl font-bold text-stone-900 dark:text-stone-100">{data.name.trim()}</p>
                                    <p className="mt-1 text-lg text-stone-800 dark:text-stone-200">{t(`staff.role_${data.role}`)}{acrossBranches ? '' : ` · ${branch?.name ?? ''}`}</p>
                                </div>
                                <ReviewList
                                    onEdit={wizard.goTo}
                                    rows={[
                                        { label: t('staff.r_name'), value: data.name.trim(), step: stepOf('who') },
                                        { label: t('staff.r_login'), value: data.phone.trim(), step: stepOf('who') },
                                        { label: t('staff.r_title'), value: data.title.trim() ? `${data.title.trim()} (${t('staff.r_label_only')})` : t('staff.r_none'), step: stepOf('who') },
                                        { label: t('staff.r_role'), value: t(`staff.role_${data.role}`), step: stepOf('access') },
                                        { label: t('staff.r_branch'), value: acrossBranches ? t('staff.all_branches') : branch?.name ?? '', step: branches.length > 1 || acrossBranches ? stepOf('access') : undefined },
                                        ...(askClass ? [{ label: t('staff.r_class'), value: chosenClass?.label ?? t('staff.r_none'), step: stepOf('access') }] : []),
                                    ]}
                                />
                                <Disclosure title={t('staff.r_can_do')} data-testid="can-do"><PermissionSummary names={effective} /></Disclosure>
                                {data.role === 'owner' && <Alert tone="warning" title={t('staff.owner_warn_title')}>{t('staff.owner_warn_body')}</Alert>}

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
                                    <PasswordField id="staff_temp_password" label={t('staff.pw_label')} hint={t('staff.pw_hint')} error={pw !== '' && !pwOk ? t('staff.err_password') : errors.temporary_password} value={pw} onChange={set('temporary_password')} autoComplete="new-password" />
                                )}

                                <Alert tone="info" title={t('staff.give_title')}>{t('staff.give_body')}</Alert>
                            </div>
                        )}
                    </WizardShell>
                </Card>
            </Page>
        </AuthenticatedLayout>
    );
}
