import Avatar from '@/Components/Avatar';
import CredentialsPanel from '@/Components/CredentialsPanel';
import { CannotManageNote, PermissionPicker, PermissionSummary, ROLE_ORDER, TitleVersusAccess } from '@/Components/StaffParts';
import Modal from '@/Components/Modal';
import Pagination from '@/Components/Pagination';
import SearchInput from '@/Components/SearchInput';
import { Alert, BackLink, Button, EmptyState, Field, FlashMessages, Input, Page, Select, StatusBadge, useConfirm } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { ArrowDownTrayIcon, PlusIcon } from '@heroicons/react/24/outline';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';

// The staff list. Each person shows their JOB TITLE (only a label) and their SYSTEM ROLE (what decides access)
// as two clearly named things. Adding someone is the three-step wizard (Staff/Wizard); "Change" here edits the
// label, phone, whether they may sign in, and -- for branch admins -- their individual permissions.

function ViewStaffPanel({ staff, onClose }) {
    const { t } = useTranslation();

    const signIn = staff.sign_in_lock?.locked ? t('staff.sign_in_locked') : staff.sign_in_lock?.waiting ? t('staff.sign_in_paused') : t('status.active');

    return (
        <div className="space-y-6 p-6" data-testid="view-staff">
            <div>
                <h2 className="text-2xl font-bold text-stone-900 dark:text-stone-100">{staff.name}</h2>
                <p className="mt-1 text-base text-stone-600 dark:text-stone-400">{t(`staff.role_${staff.role}`)}{staff.title ? ` · ${staff.title}` : ''}</p>
            </div>
            <dl className="grid grid-cols-[auto,1fr] gap-x-4 gap-y-2 text-base">
                <dt className="text-stone-600 dark:text-stone-400">{t('staff.phone')}</dt>
                <dd className="text-stone-900 dark:text-stone-100">{staff.phone || '—'}</dd>
                <dt className="text-stone-600 dark:text-stone-400">{t('staff.email')}</dt>
                <dd className="text-stone-900 dark:text-stone-100">{staff.email || '—'}</dd>
                <dt className="text-stone-600 dark:text-stone-400">{t('staff.col_branch')}</dt>
                <dd className="text-stone-900 dark:text-stone-100">{staff.branch ?? t('staff.all_branches')}</dd>
                <dt className="text-stone-600 dark:text-stone-400">{t('staff.status_label')}</dt>
                <dd className="text-stone-900 dark:text-stone-100">{t(`status.${staff.status}`)}</dd>
                <dt className="text-stone-600 dark:text-stone-400">{t('staff.col_last_login')}</dt>
                <dd className="text-stone-900 dark:text-stone-100" data-testid="view-last-login">{staff.last_login_at ?? t('staff.never_signed_in')}</dd>
                <dt className="text-stone-600 dark:text-stone-400">{t('staff.pw_question')}</dt>
                <dd className="text-stone-900 dark:text-stone-100">{staff.must_change_password ? t('staff.must_change') : t('staff.col_password_ok')}</dd>
                <dt className="text-stone-600 dark:text-stone-400">{t('staff.sign_in_status')}</dt>
                <dd className="text-stone-900 dark:text-stone-100" data-testid="view-sign-in-status">{signIn}</dd>
            </dl>
            <div>
                <h3 className="text-lg font-semibold text-stone-900 dark:text-stone-100">{t('staff.r_can_do')}</h3>
                <div className="mt-2"><PermissionSummary names={staff.permissions} /></div>
            </div>
            <div className="flex justify-end">
                <Button variant="secondary" onClick={onClose}>{t('common.cancel')}</Button>
            </div>
        </div>
    );
}

function EditStaffForm({ staff, availablePermissions, permissionGroups, tierDefaults, myPermissions, assignableRoles, branches, isOwnAccount, onDone }) {
    const { t } = useTranslation();
    const mine = new Set(myPermissions);
    const beyond = staff.permissions.filter((p) => !mine.has(p));
    const roles = ROLE_ORDER.filter((r) => assignableRoles.includes(r) || r === staff.role);
    const orgWideRole = (r) => r === 'owner' || r === 'auditor';

    const { data, setData, patch, processing, errors, clearErrors } = useForm({
        name: staff.name,
        title: staff.title ?? '',
        phone: staff.phone ?? '',
        status: staff.status,
        role: staff.role,
        branch_id: staff.branch_id ?? '',
        // Switching to a different role starts from that role's usual permissions, not whatever this
        // person happened to have before -- the server enforces this too (see StaffController::update()).
        permissions: staff.permissions.filter((p) => mine.has(p)),
    });
    const set = (k) => (v) => { clearErrors(k); setData(k, v); };
    const changeRole = (r) => {
        clearErrors('role');
        setData((d) => ({ ...d, role: r, permissions: r === 'branch_admin' ? (tierDefaults[r] ?? []).filter((p) => mine.has(p)) : [] }));
    };

    const submit = (e) => {
        e.preventDefault();
        patch(route('staff.update', staff.id), { onSuccess: onDone, preserveScroll: true });
    };

    const customises = data.role === 'branch_admin';
    const roleChanged = data.role !== staff.role;
    const effective = customises ? data.permissions : (tierDefaults[data.role] ?? []);
    const needsBranch = roles.length > 1 && !orgWideRole(data.role);

    return (
        <form onSubmit={submit} className="space-y-6 p-6" data-testid="edit-staff">
            <div>
                <h2 className="text-2xl font-bold text-stone-900 dark:text-stone-100">{t('staff.edit_title', { name: staff.name })}</h2>
                <p className="mt-1 text-base text-stone-600 dark:text-stone-400">{t(`staff.role_${staff.role}`)}{staff.email ? ` · ${staff.email}` : ''}</p>
            </div>

            <Field id="edit_name" label={t('staff.name')} error={errors.name}>
                <Input value={data.name} onChange={(e) => set('name')(e.target.value)} maxLength={255} autoComplete="off" />
            </Field>
            <Field id="edit_title" label={t('staff.title_label')} hint={t('staff.title_hint')} optional error={errors.title}>
                <Input value={data.title} onChange={(e) => set('title')(e.target.value)} maxLength={255} autoComplete="off" />
            </Field>
            <Field id="edit_phone" label={t('staff.phone')} hint={staff.can_sign_in_with_phone ? t('staff.phone_change_hint') : t('staff.phone_add_hint')} optional error={errors.phone}>
                <Input type="tel" inputMode="tel" value={data.phone} onChange={(e) => set('phone')(e.target.value)} maxLength={50} autoComplete="off" />
            </Field>

            {isOwnAccount ? (
                <Field id="edit_role" label={t('staff.col_role')} hint={t('staff.cannot_change_own_role')}>
                    <Input value={t(`staff.role_${staff.role}`)} disabled />
                </Field>
            ) : roles.length > 1 ? (
                <Field id="edit_role" label={t('staff.col_role')} hint={t('staff.role_change_hint')} error={errors.role}>
                    <Select value={data.role} onChange={(e) => changeRole(e.target.value)}>
                        {roles.map((r) => <option key={r} value={r}>{t(`staff.role_${r}`)}</option>)}
                    </Select>
                </Field>
            ) : (
                <Field id="edit_role" label={t('staff.col_role')}>
                    <Input value={t(`staff.role_${staff.role}`)} disabled />
                </Field>
            )}

            {needsBranch && (
                <Field id="edit_branch" label={t('enroll.branch_label')} error={errors.branch_id}>
                    <Select value={data.branch_id} onChange={(e) => set('branch_id')(e.target.value)}>
                        <option value="">{t('enroll.branch_placeholder')}</option>
                        {branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
                    </Select>
                </Field>
            )}

            {roleChanged && <Alert tone="warning">{t('staff.role_change_warning', { role: t(`staff.role_${data.role}`) })}</Alert>}

            <TitleVersusAccess compact />

            {customises ? (
                <div className="space-y-3">
                    <div>
                        <h3 className="text-lg font-semibold text-stone-900 dark:text-stone-100">{t('staff.perm_head')}</h3>
                        <p className="text-base text-stone-600 dark:text-stone-400">{t('staff.perm_intro')}</p>
                    </div>
                    <CannotManageNote names={beyond} />
                    <PermissionPicker groups={permissionGroups} value={data.permissions} onChange={set('permissions')} myPermissions={myPermissions} defaults={tierDefaults.branch_admin} />
                </div>
            ) : (
                <div className="space-y-2" data-testid="fixed-permissions">
                    <h3 className="text-lg font-semibold text-stone-900 dark:text-stone-100">{t('staff.fixed_head', { role: t(`staff.role_${staff.role}`) })}</h3>
                    <p className="text-base text-stone-600 dark:text-stone-400">{t('staff.fixed_intro')}</p>
                    <PermissionSummary names={effective} />
                </div>
            )}

            <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                <Button variant="secondary" onClick={onDone}>{t('common.cancel')}</Button>
                <Button type="submit" busy={processing}>{t('staff.save_changes')}</Button>
            </div>
        </form>
    );
}

export default function Index({ staff, staffMeta, search: initialSearch, assignableRoles, availablePermissions, permissionGroups, tierDefaults, myPermissions, branches, canManage, canView }) {
    const { t } = useTranslation();
    const [editing, setEditing] = useState(null);
    const [viewing, setViewing] = useState(null);
    const [search, setSearch] = useState(initialSearch ?? '');
    const [ask, confirmDialog] = useConfirm();
    const { flash, auth } = usePage().props;
    const me = auth?.user?.id;
    const credentials = flash?.credentials;
    const [hidden, setHidden] = useState(null);

    const unlock = async (s) => {
        const ok = await ask({
            tone: 'primary',
            title: t('staff.unlock_title', { name: s.name }),
            body: <p>{t('staff.unlock_body')}</p>,
            confirmLabel: t('staff.unlock_confirm', { name: s.name }),
        });
        if (ok) router.post(route('staff.unlock-sign-in', s.id), {}, { preserveScroll: true });
    };

    const disable = async (s) => {
        const ok = await ask({
            tone: 'danger',
            title: t('staff.disable_title', { name: s.name }),
            body: <><p>{t('staff.disable_body1', { name: s.name })}</p><p>{t('staff.disable_body2')}</p></>,
            confirmLabel: t('staff.disable_confirm', { name: s.name }),
        });
        if (ok) router.post(route('staff.deactivate', s.id), {}, { preserveScroll: true });
    };

    const reactivate = async (s) => {
        const ok = await ask({
            tone: 'primary',
            title: t('staff.enable_title', { name: s.name }),
            body: <p>{t('staff.enable_body')}</p>,
            confirmLabel: t('staff.enable_confirm', { name: s.name }),
        });
        if (ok) router.post(route('staff.reactivate', s.id), {}, { preserveScroll: true });
    };

    const reactivateWithReset = async (s) => {
        const ok = await ask({
            tone: 'primary',
            title: t('staff.enable_reset_title', { name: s.name }),
            body: <p>{t('staff.enable_reset_body')}</p>,
            confirmLabel: t('staff.enable_reset_confirm', { name: s.name }),
        });
        if (ok) router.post(route('staff.reactivate', s.id), { reset_password: true }, { preserveScroll: true });
    };

    const resetPassword = async (s) => {
        const ok = await ask({
            tone: 'primary',
            title: t('staff.reset_title', { name: s.name }),
            body: <><p>{t('staff.reset_body', { name: s.name })}</p><p>{t('staff.reset_body2')}</p></>,
            confirmLabel: t('staff.reset_confirm', { name: s.name }),
        });
        if (ok) router.post(route('staff.reset-password', s.id), {}, { preserveScroll: true });
    };

    const goToPage = (page) => router.get(route('staff.index'), { search: search || undefined, page }, { preserveState: true, preserveScroll: true, replace: true });

    // search is resolved on the server (StaffController@staffQuery) so the list stays paginated however many
    // staff match; typing is debounced so each keystroke doesn't fire its own request
    useEffect(() => {
        const handle = setTimeout(() => {
            if (search !== (initialSearch ?? '')) {
                router.get(route('staff.index'), { search: search || undefined, page: 1 }, { preserveState: true, preserveScroll: true, replace: true });
            }
        }, 300);
        return () => clearTimeout(handle);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">{t('staff.page_title')}</h2>}>
            <Page
                title={t('staff.page_title')}
                description={t('staff.page_desc')}
                width="wide"
                back={<BackLink big={auth.role === 'teacher'} href={route('dashboard')}>{t('common.back')}</BackLink>}
                actions={
                    <>
                        <Button as="a" href={route('staff.export')} variant="secondary" icon={ArrowDownTrayIcon}>{t('staff.export')}</Button>
                        {canManage && <Button as={Link} href={route('staff.create')} icon={PlusIcon}>{t('staff.add_button')}</Button>}
                    </>
                }
            >
                <FlashMessages errorKeys={['reset', 'status']} />
                {credentials && hidden !== credentials.password && (
                    <div data-testid="reset-result">
                        <CredentialsPanel credentials={credentials} tone="notice" />
                        <div className="mt-3"><Button variant="secondary" size="md" onClick={() => setHidden(credentials.password)}>{t('cred.hide')}</Button></div>
                    </div>
                )}

                <div className="max-w-md">
                    <SearchInput value={search} onChange={setSearch} placeholder={t('staff.search')} />
                </div>

                {staff.length === 0 ? (
                    <EmptyState title={staffMeta.total === 0 ? t('staff.empty_title') : t('staff.no_match')}>{staffMeta.total === 0 ? t('staff.empty_body') : undefined}</EmptyState>
                ) : (
                    <ul className="grid gap-4 lg:grid-cols-2" data-testid="staff-list">
                        {staff.map((s) => (
                            <li key={s.id} data-testid="staff-row" data-status={s.status} className={`rounded-2xl border-2 p-5 ${s.status === 'inactive' ? 'border-stone-300 bg-stone-100 dark:border-stone-600 dark:bg-stone-900/60' : 'border-stone-200 bg-white dark:border-stone-700 dark:bg-stone-800'}`}>
                                <div className="flex flex-wrap items-start justify-between gap-2">
                                    <div className="flex min-w-0 items-start gap-3">
                                        <Avatar src={s.photo_url} name={s.name} size={48} fallback="initials" />
                                        <div className="min-w-0">
                                            <p className="text-lg font-bold text-stone-900 dark:text-stone-100">{s.name}</p>
                                            <p className="break-all text-sm text-stone-600 dark:text-stone-400" data-testid="staff-contact">{[s.phone, s.email].filter(Boolean).join(' · ') || '—'}</p>
                                        </div>
                                    </div>
                                    <div className="flex flex-wrap gap-2">
                                        <StatusBadge status={s.status} label={s.status === 'inactive' ? t('staff.disabled_badge') : undefined} />
                                        {s.must_change_password && <StatusBadge tone="warning" label={t('staff.must_change')} />}
                                        {!s.can_sign_in_with_phone && <StatusBadge tone="neutral" label={t('staff.no_phone')} />}
                                        {s.sign_in_lock?.locked && <StatusBadge tone="danger" label={t('staff.sign_in_locked')} />}
                                        {s.sign_in_lock?.waiting && !s.sign_in_lock?.locked && <StatusBadge tone="danger" label={t('staff.sign_in_paused')} />}
                                    </div>
                                </div>
                                <dl className="mt-3 grid grid-cols-[auto,1fr] gap-x-4 gap-y-1 text-base">
                                    <dt className="text-stone-600 dark:text-stone-400">{t('staff.col_role')}</dt>
                                    <dd className="font-semibold text-stone-900 dark:text-stone-100">{t(`staff.role_${s.role}`)}</dd>
                                    <dt className="text-stone-600 dark:text-stone-400">{t('staff.col_title')}</dt>
                                    <dd className="text-stone-900 dark:text-stone-100">{s.title ?? '—'}</dd>
                                    <dt className="text-stone-600 dark:text-stone-400">{t('staff.col_branch')}</dt>
                                    <dd className="text-stone-900 dark:text-stone-100">{s.branch ?? t('staff.all_branches')}</dd>
                                    <dt className="text-stone-600 dark:text-stone-400">{t('staff.col_last_login')}</dt>
                                    <dd className="text-stone-900 dark:text-stone-100">{s.last_login_at ?? t('staff.never_signed_in')}</dd>
                                </dl>
                                <div className="mt-4 flex flex-wrap gap-3">
                                    {canView && <Button size="md" variant="quiet" onClick={() => setViewing(s)}>{t('staff.view_button')}</Button>}
                                    {canManage && <Button size="md" variant="secondary" onClick={() => setEditing(s)}>{t('staff.change')}</Button>}
                                    {canManage && <Button size="md" variant="quiet" onClick={() => resetPassword(s)}>{t('staff.reset_button')}</Button>}
                                    {canManage && s.sign_in_lock?.waiting && <Button size="md" variant="quiet" onClick={() => unlock(s)}>{t('staff.unlock_button')}</Button>}
                                    {canManage && s.status === 'active' && s.id !== me && <Button size="md" variant="quiet" className="!text-red-700 hover:!bg-red-50 dark:!text-red-300" onClick={() => disable(s)}>{t('staff.disable_button')}</Button>}
                                    {canManage && s.status === 'inactive' && <Button size="md" variant="secondary" onClick={() => reactivate(s)}>{t('staff.enable_button')}</Button>}
                                    {canManage && s.status === 'inactive' && <Button size="md" variant="quiet" onClick={() => reactivateWithReset(s)}>{t('staff.enable_reset_button')}</Button>}
                                </div>
                            </li>
                        ))}
                    </ul>
                )}

                <Pagination meta={staffMeta} onPageChange={goToPage} />
            </Page>

            {confirmDialog}
            <Modal show={!!viewing} onClose={() => setViewing(null)} maxWidth="xl">
                {viewing && <ViewStaffPanel staff={viewing} onClose={() => setViewing(null)} />}
            </Modal>
            <Modal show={!!editing} onClose={() => setEditing(null)} maxWidth="2xl">
                {editing && (
                    <EditStaffForm
                        key={editing.id}
                        staff={editing}
                        availablePermissions={availablePermissions}
                        permissionGroups={permissionGroups}
                        tierDefaults={tierDefaults}
                        myPermissions={myPermissions}
                        assignableRoles={assignableRoles}
                        branches={branches}
                        isOwnAccount={editing.id === me}
                        onDone={() => setEditing(null)}
                    />
                )}
            </Modal>
        </AuthenticatedLayout>
    );
}
