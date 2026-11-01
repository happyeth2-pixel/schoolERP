import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Avatar from '@/Components/Avatar';
import CredentialsPanel from '@/Components/CredentialsPanel';
import Pagination from '@/Components/Pagination';
import { Alert, BackLink, Button, Card, EmptyState, FlashMessages, Input, Page, StatusBadge, SuccessPanel } from '@/Components/ui';
import { ArrowDownTrayIcon, BanknotesIcon, IdentificationIcon, MagnifyingGlassIcon, UserPlusIcon, UsersIcon, XMarkIcon } from '@heroicons/react/24/outline';
import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';

// "Find Student": one big search box that answers as you type, results as simple cards (name, grade and section,
// parent or guardian, phone, status) with one large "Open Student" button. Nobody has to know the exact name:
// it matches any words of the name in any order, the student number, the class, a parent's name, the mother's name,
// and a parent's or emergency phone number in any way of writing it. With nothing typed it shows the most recently
// enrolled students. The search itself is resolved by the database (StudentController@index), paginated, rather
// than loaded whole into the browser and filtered here -- a school with hundreds of students never has to send
// (or search through) everyone's name just to open one profile. The phone numbers SHOWN on a result are fetched
// only for the few results on screen (StudentController@contacts), same as before.
// Keyboard: "/" focuses the box, Enter opens the first result, the arrow keys walk the results, Escape clears.
// `?task=payment|card` (from the home page) only adds a banner and one extra button: it grants nothing.

function ResultCard({ s, contact, task, canManage }) {
    const { t } = useTranslation();
    const guardians = contact ?? null;
    const phones = guardians ? guardians.filter((g) => g.phone) : [];

    return (
        <Card className="!p-4 flex flex-col gap-3 md:flex-row md:items-center md:justify-between" data-testid="student-result" data-student-id={s.id}>
            <div className="flex min-w-0 items-start gap-3">
                <Avatar src={s.photo_url} name={s.name} size={48} shape="rounded" fallback="icon" />
                <div className="min-w-0 space-y-1">
                    <p className="text-xl font-semibold text-stone-900 dark:text-stone-100" data-testid="result-name">{s.name}</p>
                    <p className="text-base text-stone-800 dark:text-stone-200" data-testid="result-class">
                        {s.grade ? t('find.class_line', { grade: s.grade, section: s.section_name }) : t('find.no_class')} · {t('find.id_number', { id: s.student_id_number })}
                    </p>
                    <p className={`text-base ${s.guardians.length ? 'text-stone-700 dark:text-stone-300' : 'font-medium text-amber-700 dark:text-amber-400'}`} data-testid="result-parent">
                        {s.guardians.length ? t('find.parents', { names: s.guardians.join(', ') }) : t('find.no_parent')}
                    </p>
                    {canManage && phones.length > 0 && (
                        <p className="text-base text-stone-700 dark:text-stone-300" data-testid="result-phone">{t('find.phone_line', { phones: phones.map((g) => g.phone).join(' · ') })}</p>
                    )}
                    {canManage && guardians && s.guardians.length > 0 && phones.length === 0 && (
                        <p className="text-base text-stone-600 dark:text-stone-400" data-testid="result-phone">{t('find.no_phone')}</p>
                    )}
                    <p><StatusBadge status={s.status} label={s.status.charAt(0).toUpperCase() + s.status.slice(1)} /></p>
                </div>
            </div>
            <div className="flex flex-col gap-3 sm:flex-row md:flex-col lg:flex-row">
                <Button as={Link} href={route('students.desk', s.id)} variant="primary" data-testid="open-student" data-result-link>{t('find.open_student')}</Button>
                {task === 'payment' && <Button as={Link} href={`${route('students.desk', s.id)}#desk-fees`} variant="secondary" icon={BanknotesIcon} data-testid="task-payment" data-result-link>{t('find.task_payment_button')}</Button>}
                {task === 'card' && <Button as={Link} href={route('id-card.show', s.id)} variant="secondary" icon={IdentificationIcon} data-testid="task-card" data-result-link>{t('find.print_id_card')}</Button>}
            </div>
        </Card>
    );
}

export default function Index({ students, studentsMeta, q: initialQ, justRegistered, canManage }) {
    const { t } = useTranslation();
    const { auth, flash } = usePage().props;
    const credentials = flash?.credentials;
    const [hiddenPassword, setHiddenPassword] = useState(null);
    const [q, setQ] = useState(initialQ ?? '');
    const [contacts, setContacts] = useState({});   // student id -> [{ name, relationship, phone }], only for results that were on screen
    const inputRef = useRef(null);
    const listRef = useRef(null);
    const typed = q.trim();
    const task = typeof window !== 'undefined' ? new URLSearchParams(window.location.search).get('task') : null;
    const activeTask = ['payment', 'card'].includes(task) ? task : null;

    const goToPage = (page) => router.get(route('students.index'), { q: typed || undefined, task: activeTask ?? undefined, page }, { preserveState: true, preserveScroll: true, replace: true });

    // typing is debounced so each keystroke doesn't fire its own request; the server (StudentController@index)
    // does the actual matching -- by name, id number, class, mother's name, guardian name or phone -- and returns
    // one real page of results, never the whole roster
    useEffect(() => {
        const handle = setTimeout(() => {
            if (typed !== (initialQ ?? '')) {
                router.get(route('students.index'), { q: typed || undefined, task: activeTask ?? undefined, page: 1 }, {
                    preserveState: true, preserveScroll: true, replace: true,
                });
            }
        }, 300);
        return () => clearTimeout(handle);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [typed]);

    const clear = () => { setQ(''); inputRef.current?.focus(); };

    // the phone numbers of the results currently on screen, and nobody else's
    const wanted = canManage ? students.filter((s) => !(s.id in contacts)).map((s) => s.id) : [];
    const wantedKey = wanted.join(',');
    useEffect(() => {
        if (!wantedKey) return undefined;
        let cancelled = false;
        const handle = setTimeout(() => {
            fetch(route('students.contacts', { ids: wantedKey }), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                .then((r) => (r.ok ? r.json() : null))
                .then((json) => {
                    if (cancelled || !json) return;
                    setContacts((c) => ({ ...c, ...Object.fromEntries(wantedKey.split(',').map((id) => [Number(id), json.contacts[id] ?? []])) }));
                })
                .catch(() => {});
        }, 120);
        return () => { cancelled = true; clearTimeout(handle); };
    }, [wantedKey]);

    // keyboard: "/" jumps to the search box from anywhere on the page
    useEffect(() => {
        const onKey = (e) => {
            const el = document.activeElement;
            const typing = el && (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' || el.tagName === 'SELECT' || el.isContentEditable);
            if (e.key === '/' && !typing && !e.ctrlKey && !e.metaKey && !e.altKey) { e.preventDefault(); inputRef.current?.focus(); inputRef.current?.select(); }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, []);

    const links = () => Array.from(listRef.current?.querySelectorAll('[data-testid=open-student]') ?? []);
    const onInputKey = (e) => {
        if (e.key === 'Escape' && q) { e.preventDefault(); clear(); }
        if (e.key === 'ArrowDown') { const first = links()[0]; if (first) { e.preventDefault(); first.focus(); } }
        if (e.key === 'Enter' && students.length > 0) { e.preventDefault(); router.visit(route('students.desk', students[0].id)); }
    };
    const onListKey = (e) => {
        if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;
        const all = links();
        const card = e.target.closest('[data-testid=student-result]');
        const idx = all.findIndex((a) => card?.contains(a));
        if (idx < 0) return;
        e.preventDefault();
        if (e.key === 'ArrowDown') all[Math.min(all.length - 1, idx + 1)]?.focus();
        else if (idx === 0) inputRef.current?.focus();
        else all[idx - 1]?.focus();
    };

    return (
        <AuthenticatedLayout header={<h2 className="truncate text-lg font-semibold text-stone-800 dark:text-stone-200">{t('find.title')}</h2>}>
            <Page
                title={t('find.title')}
                width="wide"
                back={<BackLink big={auth.role === 'teacher'} href={route('dashboard')}>{t('common.back')}</BackLink>}
                actions={canManage && <Button as={Link} href={route('students.create')} icon={UserPlusIcon}>{t('find.register')}</Button>}
            >
                {/* the panel below says it better; a warning (e.g. a phone number already used) still shows */}
                <FlashMessages skip={justRegistered ? ['success'] : []} />

                {credentials && hiddenPassword !== credentials.password && (
                    <div data-testid="guardian-credentials">
                        <CredentialsPanel credentials={credentials} />
                        <div className="mt-3"><Button variant="secondary" size="md" onClick={() => setHiddenPassword(credentials.password)}>{t('cred.hide')}</Button></div>
                    </div>
                )}

                {justRegistered && (
                    <SuccessPanel
                        title={t('find.registered_title', { name: justRegistered.name })}
                        actions={
                            <>
                                <Button as={Link} href={route('id-card.show', justRegistered.id)} icon={IdentificationIcon}>{t('find.print_id_card')}</Button>
                                <Button as={Link} href={route('students.desk', justRegistered.id)} variant="secondary">{t('find.open_student')}</Button>
                                {canManage && <Button as={Link} href={route('students.create')} variant="secondary" icon={UserPlusIcon}>{t('find.register_another')}</Button>}
                            </>
                        }
                    >
                        {t('find.registered_body', {
                            id: justRegistered.student_id_number,
                            section: justRegistered.section ?? t('find.no_class'),
                            parents: justRegistered.guardians.length ? justRegistered.guardians.join(', ') : t('find.no_parent_short'),
                        })}
                    </SuccessPanel>
                )}

                {activeTask && <div data-testid="task-banner"><Alert tone="info">{t(`find.task_${activeTask}`)}</Alert></div>}

                <div className="rounded-2xl border-2 border-brand-300 bg-brand-50 p-4 sm:p-6 dark:border-brand-700 dark:bg-brand-900/20" data-testid="quick-find">
                    <label htmlFor="student-search" className="mb-3 block text-2xl font-bold text-stone-900 dark:text-stone-100">{t('find.label')}</label>
                    <div className="relative">
                        <MagnifyingGlassIcon className="pointer-events-none absolute left-4 top-1/2 h-7 w-7 -translate-y-1/2 text-stone-500" aria-hidden="true" />
                        <Input
                            id="student-search"
                            ref={inputRef}
                            type="search"
                            inputMode="search"
                            enterKeyHint="search"
                            autoComplete="off"
                            autoFocus={!justRegistered}
                            placeholder={t('find.placeholder')}
                            value={q}
                            onChange={(e) => setQ(e.target.value)}
                            onKeyDown={onInputKey}
                            className="min-h-[68px] pl-14 pr-14 text-xl"
                        />
                        {q && (
                            <button type="button" onClick={clear} aria-label={t('find.clear')} title={t('find.clear')} className="absolute right-1 top-1/2 flex h-14 w-14 -translate-y-1/2 items-center justify-center rounded-xl text-stone-600 hover:bg-stone-100 hover:text-stone-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 dark:text-stone-300 dark:hover:bg-stone-700">
                                <XMarkIcon className="h-7 w-7" aria-hidden="true" />
                            </button>
                        )}
                    </div>
                    <p className="mt-3 text-base text-stone-700 dark:text-stone-300">{t('find.hint')}</p>
                    <p className="mt-1 hidden text-sm text-stone-600 sm:block dark:text-stone-400" data-testid="keyboard-hint">{t('find.keyboard_hint')}</p>
                </div>

                {studentsMeta.total === 0 && !typed ? (
                    <Card>
                        <EmptyState title={t('find.none_yet_title')} action={canManage && <Button as={Link} href={route('students.create')} icon={UserPlusIcon}>{t('find.register')}</Button>}>
                            {t('find.none_yet_body')}
                        </EmptyState>
                    </Card>
                ) : (
                    <section aria-live="polite">
                        <h2 className="mb-3 text-lg font-semibold text-stone-900 dark:text-stone-100" data-testid="results-heading">
                            {typed ? t('find.found_count', { count: studentsMeta.total }) : t('find.recent', { total: studentsMeta.total })}
                        </h2>

                        {students.length === 0 ? (
                            <Card data-testid="no-results">
                                <EmptyState
                                    icon={MagnifyingGlassIcon}
                                    title={t('find.nothing_title', { q: typed })}
                                    action={
                                        <div className="flex flex-col gap-3 sm:flex-row">
                                            <Button variant="secondary" onClick={clear}>{t('find.clear')}</Button>
                                            {canManage && <Button as={Link} href={route('students.create')} icon={UserPlusIcon}>{t('find.register')}</Button>}
                                            <Button as={Link} href={route('parents.index')} variant="secondary" icon={UsersIcon}>{t('find.try_parents')}</Button>
                                        </div>
                                    }
                                >
                                    {t('find.nothing_body')}
                                </EmptyState>
                            </Card>
                        ) : (
                            <div className="space-y-3">
                                <div className="space-y-2" ref={listRef} onKeyDown={onListKey}>
                                    {students.map((s) => <ResultCard key={s.id} s={s} contact={contacts[s.id]} task={activeTask} canManage={canManage} />)}
                                </div>
                                <Pagination meta={studentsMeta} onPageChange={goToPage} />
                            </div>
                        )}
                    </section>
                )}

                <div>
                    <Button as="a" href={route('students.export')} variant="quiet" size="md" icon={ArrowDownTrayIcon}>{t('find.export')}</Button>
                </div>
            </Page>
        </AuthenticatedLayout>
    );
}
