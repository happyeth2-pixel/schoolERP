import Avatar from '@/Components/Avatar';
import CredentialsPanel from '@/Components/CredentialsPanel';
import Modal from '@/Components/Modal';
import Pagination from '@/Components/Pagination';
import { Alert, BackLink, Button, Card, EmptyState, Input, Page, StatusBadge, useConfirm } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { MagnifyingGlassIcon, UserGroupIcon, UserPlusIcon, XMarkIcon } from '@heroicons/react/24/outline';
import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';

// "Find Parent": a parent or guardian by name, phone number (however it is typed), email, or their child's name or
// student number. Each child is a link to that student's page (only for people who may open students). A parent
// account is OPTIONAL -- a family with none is served exactly as well at the school desk -- so "Add a parent
// account" is one more way in, alongside linking a parent during registration or from a student's own profile.

function ViewParentPanel({ parent, onClose }) {
    const { t } = useTranslation();
    const signIn = parent.sign_in_lock?.locked ? t('staff.sign_in_locked') : parent.sign_in_lock?.waiting ? t('staff.sign_in_paused') : t('status.active');

    return (
        <div className="space-y-6 p-6" data-testid="view-parent">
            <h2 className="text-2xl font-bold text-stone-900 dark:text-stone-100">{parent.name}</h2>
            <dl className="grid grid-cols-[auto,1fr] gap-x-4 gap-y-2 text-base">
                <dt className="text-stone-600 dark:text-stone-400">{t('staff.phone')}</dt>
                <dd className="text-stone-900 dark:text-stone-100">{parent.phone_display || '—'}</dd>
                <dt className="text-stone-600 dark:text-stone-400">{t('staff.email')}</dt>
                <dd className="text-stone-900 dark:text-stone-100">{parent.email || '—'}</dd>
                <dt className="text-stone-600 dark:text-stone-400">{t('staff.status_label')}</dt>
                <dd className="text-stone-900 dark:text-stone-100">{t(`status.${parent.status}`)}</dd>
                <dt className="text-stone-600 dark:text-stone-400">{t('staff.col_last_login')}</dt>
                <dd className="text-stone-900 dark:text-stone-100" data-testid="view-last-login">{parent.last_login_at ?? t('staff.never_signed_in')}</dd>
                <dt className="text-stone-600 dark:text-stone-400">{t('staff.pw_question')}</dt>
                <dd className="text-stone-900 dark:text-stone-100">{parent.must_change_password ? t('staff.must_change') : t('staff.col_password_ok')}</dd>
                <dt className="text-stone-600 dark:text-stone-400">{t('staff.sign_in_status')}</dt>
                <dd className="text-stone-900 dark:text-stone-100" data-testid="view-sign-in-status">{signIn}</dd>
            </dl>
            <div>
                <h3 className="text-lg font-semibold text-stone-900 dark:text-stone-100">{t('parents.r_children')}</h3>
                {parent.children.length === 0 ? (
                    <p className="mt-1 text-base text-stone-600 dark:text-stone-400">{t('parents.no_children')}</p>
                ) : (
                    <ul className="mt-1 space-y-1 text-base text-stone-900 dark:text-stone-100">
                        {parent.children.map((c) => <li key={c.id}>{c.name} ({c.relationship_label ?? c.relationship})</li>)}
                    </ul>
                )}
            </div>
            <div className="flex justify-end">
                <Button variant="secondary" onClick={onClose}>{t('common.cancel')}</Button>
            </div>
        </div>
    );
}

export default function Index({ parents, parentsMeta, search: initialSearch, canOpenStudents, canManage, canView }) {
    const [viewing, setViewing] = useState(null);
    const { t } = useTranslation();
    const { auth, flash } = usePage().props;
    const credentials = flash?.credentials;
    const [ask, confirmDialog] = useConfirm();
    const [hidden, setHidden] = useState(null);
    const [search, setSearch] = useState(initialSearch ?? '');
    const inputRef = useRef(null);
    const typed = search.trim();

    const goToPage = (page) => router.get(route('parents.index'), { search: typed || undefined, page }, { preserveState: true, preserveScroll: true, replace: true });

    // the directory is searched and paginated on the server (ParentController@parentsQuery), not filtered from
    // a fully-loaded list; typing is debounced so each keystroke doesn't fire its own request
    useEffect(() => {
        const handle = setTimeout(() => {
            if (typed !== (initialSearch ?? '')) {
                router.get(route('parents.index'), { search: typed || undefined, page: 1 }, { preserveState: true, preserveScroll: true, replace: true });
            }
        }, 300);
        return () => clearTimeout(handle);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [typed]);

    const resetPassword = async (p) => {
        const ok = await ask({
            tone: 'primary',
            title: t('staff.reset_title', { name: p.name }),
            body: <><p>{t('staff.reset_body', { name: p.name })}</p><p>{t('staff.reset_body2')}</p></>,
            confirmLabel: t('staff.reset_confirm', { name: p.name }),
        });
        if (ok) router.post(route('parents.reset-password', p.id), {}, { preserveScroll: true });
    };

    const disable = async (p) => {
        const ok = await ask({
            tone: 'danger',
            title: t('staff.disable_title', { name: p.name }),
            body: <><p>{t('parents.disable_body1', { name: p.name })}</p><p>{t('parents.disable_body2')}</p></>,
            confirmLabel: t('staff.disable_confirm', { name: p.name }),
        });
        if (ok) router.post(route('parents.deactivate', p.id), {}, { preserveScroll: true });
    };

    const reactivate = async (p) => {
        const ok = await ask({
            tone: 'primary',
            title: t('staff.enable_title', { name: p.name }),
            body: <p>{t('staff.enable_body')}</p>,
            confirmLabel: t('staff.enable_confirm', { name: p.name }),
        });
        if (ok) router.post(route('parents.reactivate', p.id), {}, { preserveScroll: true });
    };

    const reactivateWithReset = async (p) => {
        const ok = await ask({
            tone: 'primary',
            title: t('staff.enable_reset_title', { name: p.name }),
            body: <p>{t('staff.enable_reset_body')}</p>,
            confirmLabel: t('staff.enable_reset_confirm', { name: p.name }),
        });
        if (ok) router.post(route('parents.reactivate', p.id), { reset_password: true }, { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">{t('parents.title')}</h2>}>
            <Page
                title={t('parents.title')}
                description={t('parents.desc')}
                width="wide"
                back={<BackLink big={auth.role === 'teacher'} href={route('dashboard')}>{t('common.back')}</BackLink>}
                actions={canManage && <Button as={Link} href={route('parents.create')} icon={UserPlusIcon}>{t('parents.add_button')}</Button>}
            >
                {flash?.success && <Alert tone="success">{flash.success}</Alert>}
                {credentials && hidden !== credentials.password && (
                    <div data-testid="reset-result">
                        <CredentialsPanel credentials={credentials} />
                        <div className="mt-3"><Button variant="secondary" size="md" onClick={() => setHidden(credentials.password)}>{t('cred.hide')}</Button></div>
                    </div>
                )}

                <div className="rounded-2xl border-2 border-brand-300 bg-brand-50 p-4 sm:p-6 dark:border-brand-700 dark:bg-brand-900/20">
                    <label htmlFor="parent-search" className="mb-3 block text-2xl font-bold text-stone-900 dark:text-stone-100">{t('parents.label')}</label>
                    <div className="relative">
                        <MagnifyingGlassIcon className="pointer-events-none absolute left-4 top-1/2 h-7 w-7 -translate-y-1/2 text-stone-500" aria-hidden="true" />
                        <Input id="parent-search" ref={inputRef} type="search" inputMode="search" autoComplete="off" autoFocus placeholder={t('parents.placeholder')} value={search} onChange={(e) => setSearch(e.target.value)} onKeyDown={(e) => { if (e.key === 'Escape') setSearch(''); }} className="min-h-[68px] pl-14 pr-14 text-xl" />
                        {search && (
                            <button type="button" onClick={() => { setSearch(''); inputRef.current?.focus(); }} aria-label={t('find.clear')} className="absolute right-1 top-1/2 flex h-14 w-14 -translate-y-1/2 items-center justify-center rounded-xl text-stone-600 hover:bg-stone-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 dark:text-stone-300 dark:hover:bg-stone-700">
                                <XMarkIcon className="h-7 w-7" aria-hidden="true" />
                            </button>
                        )}
                    </div>
                    <p className="mt-3 text-base text-stone-700 dark:text-stone-300">{t('parents.hint')}</p>
                </div>

                <h2 className="text-lg font-semibold text-stone-900 dark:text-stone-100" data-testid="parents-count">{typed ? t('parents.found_count', { count: parentsMeta.total }) : t('parents.all_count', { count: parentsMeta.total })}</h2>

                {parents.length === 0 ? (
                    <Card data-testid="no-parents">
                        <EmptyState icon={UserGroupIcon} title={parentsMeta.total === 0 && !typed ? t('parents.none_yet') : t('parents.nothing_title', { q: typed })} action={canManage && parentsMeta.total === 0 && !typed && <Button as={Link} href={route('parents.create')} icon={UserPlusIcon}>{t('parents.add_button')}</Button>}>
                            {parentsMeta.total === 0 && !typed ? t('parents.none_yet_body') : t('parents.nothing_body')}
                        </EmptyState>
                    </Card>
                ) : (
                    <ul className="space-y-3" data-testid="parent-list">
                        {parents.map((p) => (
                            <li key={p.id} data-testid="parent-card"><Card data-status={p.status}>
                                <div className="flex flex-wrap items-start justify-between gap-2">
                                    <div className="flex min-w-0 items-center gap-3">
                                        <Avatar src={p.photo_url} name={p.name} size={44} fallback="initials" />
                                        <p className="text-xl font-semibold text-stone-900 dark:text-stone-100">{p.name}</p>
                                    </div>
                                    <div className="flex flex-wrap gap-2">
                                        {canView && <StatusBadge status={p.status} label={p.status === 'inactive' ? t('staff.disabled_badge') : undefined} />}
                                        {canView && p.must_change_password && <StatusBadge tone="warning" label={t('staff.must_change')} />}
                                        {canView && p.sign_in_lock?.locked && <StatusBadge tone="danger" label={t('staff.sign_in_locked')} />}
                                        {canView && p.sign_in_lock?.waiting && !p.sign_in_lock?.locked && <StatusBadge tone="danger" label={t('staff.sign_in_paused')} />}
                                    </div>
                                </div>
                                <p className="text-base text-stone-700 dark:text-stone-300">{[p.phone_display, p.email].filter(Boolean).join(' · ') || t('parents.no_contact')}</p>
                                <ul className="mt-3 space-y-2">
                                    {p.children.map((c) => (
                                        <li key={c.id} className="flex flex-col gap-2 rounded-xl bg-stone-50 p-3 sm:flex-row sm:items-center sm:justify-between dark:bg-stone-900/40">
                                            <span className="text-base text-stone-900 dark:text-stone-100">{c.name} <span className="text-stone-600 dark:text-stone-400">({c.relationship_label ?? c.relationship}) · {c.student_id_number}</span></span>
                                            {canOpenStudents && <Button as={Link} href={route('students.desk', c.id)} size="md" variant="secondary" data-testid="open-child">{t('find.open_student')}</Button>}
                                        </li>
                                    ))}
                                    {p.children.length === 0 && <li className="text-base text-stone-600 dark:text-stone-400">{t('parents.no_children')}</li>}
                                </ul>
                                {canView && (
                                    <p className="mt-2 text-sm text-stone-600 dark:text-stone-400">{t('staff.col_last_login')}: {p.last_login_at ?? t('staff.never_signed_in')}</p>
                                )}
                                <div className="mt-4 flex flex-wrap gap-3">
                                    {canView && <Button size="md" variant="quiet" onClick={() => setViewing(p)}>{t('staff.view_button')}</Button>}
                                    {canManage && <Button size="md" variant="quiet" onClick={() => resetPassword(p)}>{t('staff.reset_button')}</Button>}
                                    {canManage && p.status === 'active' && <Button size="md" variant="quiet" className="!text-red-700 hover:!bg-red-50 dark:!text-red-300" onClick={() => disable(p)}>{t('staff.disable_button')}</Button>}
                                    {canManage && p.status === 'inactive' && <Button size="md" variant="secondary" onClick={() => reactivate(p)}>{t('staff.enable_button')}</Button>}
                                    {canManage && p.status === 'inactive' && <Button size="md" variant="quiet" onClick={() => reactivateWithReset(p)}>{t('staff.enable_reset_button')}</Button>}
                                </div>
                            </Card></li>
                        ))}
                    </ul>
                )}

                <Pagination meta={parentsMeta} onPageChange={goToPage} />
            </Page>
            {confirmDialog}
            <Modal show={!!viewing} onClose={() => setViewing(null)} maxWidth="xl">
                {viewing && <ViewParentPanel parent={viewing} onClose={() => setViewing(null)} />}
            </Modal>
        </AuthenticatedLayout>
    );
}
