import Avatar from '@/Components/Avatar';
import InvitationNotice from '@/Components/InvitationNotice';
import Dropdown from '@/Components/Dropdown';
import { ActionTile, IconButton } from '@/Components/ui';
import i18n from '@/i18n';
import { buildNavSections } from '@/navigation';
import { announce } from '@/notifySound';
import { getStoredTheme, setTheme } from '@/theme';
import {
    Bars3Icon,
    BellIcon,
    ChevronDoubleLeftIcon,
    ChevronDoubleRightIcon,
    ChevronDownIcon,
    ChevronLeftIcon,
    ChevronRightIcon,
    GlobeAltIcon,
    MagnifyingGlassIcon,
    MoonIcon,
    SunIcon,
    XMarkIcon,
} from '@heroicons/react/24/outline';
import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';

const LANGUAGE_NAMES = { en: 'English', am: 'አማርኛ', om: 'Afaan Oromoo', ti: 'ትግርኛ' };

function LanguageSwitcher() {
    const { auth, locale } = usePage().props;
    const enabled = auth.school?.enabled_languages ?? ['en'];

    if (enabled.length <= 1) return null;

    const choose = (code) => {
        if (code === locale) return;
        i18n.changeLanguage(code);
        router.post(route('profile.locale'), { locale: code }, { preserveScroll: true, preserveState: true });
    };

    return (
        <Dropdown>
            <Dropdown.Trigger>
                <IconButton icon={GlobeAltIcon} label={i18n.t('layout.language')} />
            </Dropdown.Trigger>
            <Dropdown.Content align="right">
                {enabled.map((code) => (
                    <button
                        key={code}
                        onClick={() => choose(code)}
                        className={`block min-h-[48px] w-full px-4 py-3 text-left text-base md:min-h-0 md:py-2 md:text-sm ${code === locale ? 'font-semibold text-brand-600 dark:text-brand-400' : 'text-stone-700 dark:text-stone-300'} hover:bg-stone-100 dark:hover:bg-stone-700`}
                    >
                        {LANGUAGE_NAMES[code] ?? code}
                    </button>
                ))}
            </Dropdown.Content>
        </Dropdown>
    );
}

function NotificationBell() {
    const { t } = useTranslation();
    const { notifications, auth } = usePage().props;
    // Inertia's own `notifications` prop is only ever as fresh as the last page visit -- it cannot change
    // itself when a push arrives between visits. This local copy starts from it and is then kept current
    // by the live channel below; it re-syncs to the prop on every real navigation (the dependency), so a
    // normal page visit is always still the source of truth and nothing can drift permanently out of sync.
    const [live, setLive] = useState(notifications);
    useEffect(() => setLive(notifications), [notifications]);

    useEffect(() => {
        if (!auth?.user?.id || !window.Echo) return;
        const channel = window.Echo.private(`App.Models.User.${auth.user.id}`);
        channel.listen('.notification.created', (payload) => {
            setLive((prev) => {
                if (!prev) return prev;
                if (prev.recent.some((n) => n.id === payload.id)) return prev; // already have it (e.g. two tabs)
                return { unreadCount: prev.unreadCount + 1, recent: [payload, ...prev.recent].slice(0, 10) };
            });
            announce();
        });
        return () => { window.Echo.leave(`App.Models.User.${auth.user.id}`); };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [auth?.user?.id]);

    if (!live) return null;

    const markRead = (id) => router.post(route('notifications.read', id), {}, { preserveScroll: true });
    // Opening the bell is itself "I've seen these": mark everything read right away, same as the explicit
    // "mark all read" on the full notifications page -- the badge count should never sit at a stale
    // number once the person has actually opened the list. A no-op server-side (and skipped here) once
    // the count is already 0, so tapping the bell again doesn't fire a request for nothing.
    const openBell = () => {
        if (live.unreadCount > 0) {
            router.post(route('notifications.read-all'), {}, { preserveScroll: true });
            setLive((prev) => ({ ...prev, unreadCount: 0, recent: prev.recent.map((n) => ({ ...n, read_at: n.read_at ?? new Date().toISOString() })) }));
        }
    };

    return (
        <Dropdown>
            <Dropdown.Trigger>
                <IconButton
                    icon={BellIcon}
                    onClick={openBell}
                    label={live.unreadCount > 0 ? `${t('nav.notifications')} (${live.unreadCount})` : t('nav.notifications')}
                    badge={live.unreadCount > 0 ? (live.unreadCount > 9 ? '9+' : live.unreadCount) : null}
                />
            </Dropdown.Trigger>
            <Dropdown.Content align="right" contentClasses="py-1 bg-white dark:bg-stone-700 w-80">
                <div className="max-h-80 overflow-y-auto">
                    {live.recent.length === 0 && (
                        <p className="p-4 text-sm text-stone-400">No notifications yet.</p>
                    )}
                    {live.recent.map((n) => {
                        const Tag = n.action_url ? Link : 'button';
                        return (
                            <Tag
                                key={n.id}
                                {...(n.action_url ? { href: n.action_url } : {})}
                                onClick={() => !n.read_at && markRead(n.id)}
                                className={`block w-full px-4 py-3 text-left text-sm ${n.read_at ? '' : 'bg-brand-50 dark:bg-brand-900/20'} hover:bg-stone-100 dark:hover:bg-stone-700`}
                            >
                                <p className="font-medium text-stone-800 dark:text-stone-100">{n.title}</p>
                                <p className="text-stone-500">{n.body}</p>
                                <p className="mt-1 text-xs text-stone-400">{n.created_at}</p>
                            </Tag>
                        );
                    })}
                </div>
                <Link href={route('notifications.index')} className="block px-4 py-2 text-center text-sm text-brand-600 hover:bg-stone-100 dark:text-brand-400 dark:hover:bg-stone-700">
                    View all
                </Link>
            </Dropdown.Content>
        </Dropdown>
    );
}

// The sidebar is a dark, fixed surface in both themes -- its own brand chrome, not something that
// flips with light/dark mode -- so the active page is "obviously obvious": a solid filled pill, never
// a thin coloured line at the edge of an otherwise plain row.
/** The same unread count the header bell shows, repeated here -- "Notifications" in the menu should say
 *  how many are unread too, not just the bell icon up top (which, on a phone with the sidebar closed, is
 *  the only place it would otherwise show at all). */
function sidebarBadgeFor(route_, notifications) {
    if (route_ !== 'notifications.index') return null;
    const n = notifications?.unreadCount ?? 0;

    return n > 0 ? (n > 9 ? '9+' : n) : null;
}

function SidebarLink({ item, collapsed }) {
    const { notifications } = usePage().props;
    const Icon = item.icon;
    const base = `flex min-h-[48px] items-center gap-3 rounded-lg px-3 py-2 text-base transition md:min-h-[40px] md:gap-2.5 md:text-sm ${collapsed ? 'justify-center' : ''}`;

    if (!item.enabled) {
        return (
            <span className={`${base} cursor-not-allowed text-stone-600`} title="Coming in a later phase">
                {Icon && <Icon className="h-5 w-5 shrink-0" />}
                {!collapsed && item.label}
            </span>
        );
    }

    const active = route().current(item.route);
    const badge = sidebarBadgeFor(item.route, notifications);

    return (
        <Link
            href={route(item.route, item.params)}
            title={collapsed ? item.label : undefined}
            className={`${base} relative ${
                active ? 'bg-brand-700 font-semibold text-white' : 'text-stone-300 hover:bg-forest-800 hover:text-white'
            }`}
        >
            {Icon && <Icon className="h-5 w-5 shrink-0" />}
            {!collapsed && <span className="flex-1">{item.label}</span>}
            {badge && (
                <span className={`flex h-5 min-w-[1.25rem] shrink-0 items-center justify-center rounded-full px-1 text-[11px] font-semibold ${collapsed ? 'absolute -right-1 -top-1' : ''} ${active ? 'bg-white text-brand-700' : 'bg-red-600 text-white'}`}>
                    {badge}
                </span>
            )}
        </Link>
    );
}

const MOBILE_NAV_TINTS = [
    'bg-sky-700 text-white', 'bg-violet-700 text-white', 'bg-emerald-700 text-white', 'bg-amber-700 text-white',
    'bg-rose-700 text-white', 'bg-indigo-700 text-white', 'bg-teal-700 text-white', 'bg-fuchsia-700 text-white',
    'bg-cyan-700 text-white', 'bg-orange-700 text-white', 'bg-lime-700 text-white', 'bg-pink-700 text-white',
];

/** The mobile drawer's version of a nav item: a big, colourful icon tile instead of a thin dark row --
 *  same data, same permissions, same route, just sized and styled for a thumb instead of a mouse. */
function MobileNavTile({ item, tintIndex }) {
    const { notifications } = usePage().props;

    if (!item.enabled) {
        return (
            <span className="flex min-h-[88px] flex-col items-center justify-center gap-1.5 rounded-xl border-2 border-dashed border-stone-700 p-3 text-center text-sm font-semibold text-stone-600" title="Coming in a later phase">
                {item.icon && <item.icon className="h-6 w-6" aria-hidden="true" />}
                {item.label}
            </span>
        );
    }

    const active = route().current(item.route);
    const badge = sidebarBadgeFor(item.route, notifications);
    const tint = MOBILE_NAV_TINTS[tintIndex % MOBILE_NAV_TINTS.length];

    return (
        <Link
            href={route(item.route, item.params)}
            className={`relative flex min-h-[88px] flex-col items-center justify-center gap-1.5 rounded-xl p-3 text-center text-sm font-semibold leading-tight ${tint} ${active ? 'ring-4 ring-white' : ''}`}
        >
            {item.icon && <item.icon className="h-7 w-7" aria-hidden="true" />}
            {item.label}
            {badge && (
                <span className="absolute right-2 top-2 flex h-5 min-w-[1.25rem] items-center justify-center rounded-full bg-red-600 px-1 text-[11px] font-bold text-white">
                    {badge}
                </span>
            )}
        </Link>
    );
}

const NAV_OPEN_KEY = 'nav-open-sections';
const NAV_COLLAPSED_KEY = 'nav-collapsed';
const NAV_SCROLL_KEY = 'nav-scroll-top';

function readOpenState() {
    try {
        return JSON.parse(localStorage.getItem(NAV_OPEN_KEY) ?? '{}');
    } catch {
        return {};
    }
}

function readCollapsedState() {
    try {
        return localStorage.getItem(NAV_COLLAPSED_KEY) === '1';
    } catch {
        return false;
    }
}

function MyChildren() {
    const { t } = useTranslation();
    const { parentChildren } = usePage().props;
    if (!parentChildren || parentChildren.length === 0) return null;

    return (
        <div className="shrink-0 border-b border-stone-800 px-4 py-3">
            <p className="mb-1.5 text-xs font-semibold uppercase tracking-wider text-stone-500">{t('parent.sidebar_my_children')}</p>
            <ul className="space-y-1">
                {parentChildren.map((c) => (
                    <li key={c.id} className="flex items-baseline gap-2 text-sm">
                        <span className="font-bold text-stone-100">{c.name}</span>
                        {c.class && <span className="truncate font-bold text-brand-400">{c.class}</span>}
                    </li>
                ))}
            </ul>
        </div>
    );
}

function Sidebar({ mobileOpen, onClose }) {
    const { auth } = usePage().props;
    const { t } = useTranslation();
    const sections = buildNavSections({
        role: auth.role,
        permissions: auth.permissions ?? [],
        t,
    });

    // Progressive disclosure: a long menu (owner, full admins) folds its sections; a short one (teacher,
    // parent, front desk) is shown whole. Nothing is ever removed -- one tap opens any section, the
    // section holding the current page opens by itself, and the choice is remembered.
    const totalItems = sections.reduce((n, section) => n + section.items.length, 0);
    const collapsible = totalItems > 12;
    const activeId = sections.find((section) => section.items.some((i) => i.enabled && i.route && route().current(i.route)))?.id;
    const [stored, setStored] = useState(readOpenState);
    // "Collapsed" shrinks the whole rail to icons-only on desktop, to get the screen width back; it never
    // applies on mobile, where the sidebar is a full overlay, not a persistent rail.
    const [collapsed, setCollapsed] = useState(readCollapsedState);
    const [navSearch, setNavSearch] = useState('');
    const navRef = useRef(null);

    // A long menu (owner, branch admin -- 40+ items across a dozen sections) is a lot to scroll through
    // to find one thing. Filters by label only, case-insensitively; a section with nothing left to show
    // just disappears rather than showing an empty heading.
    const query = navSearch.trim().toLowerCase();
    const filteredSections = query
        ? sections
            .map((section) => ({ ...section, items: section.items.filter((item) => item.label.toLowerCase().includes(query)) }))
            .filter((section) => section.items.length > 0)
        : sections;

    const save = (next) => {
        setStored(next);
        try {
            localStorage.setItem(NAV_OPEN_KEY, JSON.stringify(next));
        } catch {
            // private browsing: the choice just won't be remembered
        }
    };

    const toggleCollapsed = () => {
        const next = !collapsed;
        setCollapsed(next);
        try {
            localStorage.setItem(NAV_COLLAPSED_KEY, next ? '1' : '0');
        } catch {
            // private browsing: the choice just won't be remembered
        }
    };

    useEffect(() => {
        if (collapsible && activeId && stored[activeId] === false) {
            save({ ...stored, [activeId]: true });
        }
        // only when the page (and so the active section) changes
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [activeId]);

    // Every page visit remounts this whole layout (it's not an Inertia persistent layout), which would
    // otherwise snap the menu's scroll back to the top on every click -- so whichever button was just
    // tapped scrolls out of view and has to be hunted down again. Restoring the exact scroll position
    // fixes it for every button, not just the ones near the top.
    useEffect(() => {
        const saved = Number(sessionStorage.getItem(NAV_SCROLL_KEY) ?? 0);
        if (!navRef.current || saved <= 0) return;
        const apply = () => { if (navRef.current) navRef.current.scrollTop = saved; };
        apply();
        // re-applied one frame later in case the active-section-opening effect above just changed the
        // menu's height out from under this first attempt
        const raf = requestAnimationFrame(apply);
        return () => cancelAnimationFrame(raf);
    }, []);

    const saveScroll = () => {
        try {
            sessionStorage.setItem(NAV_SCROLL_KEY, String(navRef.current?.scrollTop ?? 0));
        } catch {
            // private browsing: the position just won't be remembered
        }
    };

    const isOpen = (section, index) => Boolean(query) || !collapsible || (stored[section.id] ?? (index < 3 || section.id === activeId));

    return (
        <aside
            className={`fixed inset-y-0 left-0 z-30 flex w-[88vw] max-w-sm transform flex-col bg-forest-950 transition-all lg:sticky lg:top-0 lg:h-screen lg:translate-x-0 ${
                collapsed ? 'lg:w-16' : 'lg:w-64'
            } ${mobileOpen ? 'translate-x-0' : '-translate-x-full'}`}
        >
            <div className="flex h-16 shrink-0 items-center gap-1 border-b border-forest-800 px-4">
                <Link href="/" onClick={onClose} className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-brand-400 bg-white p-0.5">
                    <img src="/images/brand/paradise-logo.jpg" alt="School of Paradise Hills crest" className="h-full w-full rounded-full object-cover" />
                </Link>
                {!collapsed && (
                    <span className="min-w-0 flex-1 truncate pl-1.5 text-sm font-semibold text-stone-100">
                        {auth.school?.name ?? 'School ERP'}
                    </span>
                )}
                {collapsed && <span className="flex-1" aria-hidden="true" />}
                {/* Mobile: the drawer is an overlay over the page, so it needs its own obvious way to close,
                    not just "tap the dark area behind it". Desktop never shows this -- there, the rail is
                    part of the page, not a drawer. */}
                <button
                    type="button"
                    onClick={onClose}
                    aria-label={t('layout.close_menu')}
                    title={t('layout.close_menu')}
                    className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-stone-400 hover:bg-forest-800 hover:text-white lg:hidden"
                >
                    <XMarkIcon className="h-5 w-5" aria-hidden="true" />
                </button>
                {/* Desktop only: collapse the rail to icons to get the width back, or expand it again. */}
                <button
                    type="button"
                    onClick={toggleCollapsed}
                    aria-label={collapsed ? t('layout.expand_menu') : t('layout.collapse_menu')}
                    title={collapsed ? t('layout.expand_menu') : t('layout.collapse_menu')}
                    className="hidden h-9 w-9 shrink-0 items-center justify-center rounded-lg text-stone-400 hover:bg-forest-800 hover:text-white lg:flex"
                >
                    {collapsed ? <ChevronDoubleRightIcon className="h-5 w-5" aria-hidden="true" /> : <ChevronDoubleLeftIcon className="h-5 w-5" aria-hidden="true" />}
                </button>
            </div>

            {!collapsed && <MyChildren />}

            {!collapsed && (
                <div className="shrink-0 px-3 pt-3">
                    <div className="relative">
                        <MagnifyingGlassIcon className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-stone-500" aria-hidden="true" />
                        <input
                            type="search"
                            value={navSearch}
                            onChange={(e) => setNavSearch(e.target.value)}
                            placeholder={t('layout.search_menu')}
                            aria-label={t('layout.search_menu')}
                            className="h-11 w-full rounded-lg border-2 border-forest-800 bg-forest-900 pl-9 pr-3 text-sm text-stone-100 placeholder:text-stone-500 focus:border-brand-500 focus:outline-none"
                        />
                    </div>
                </div>
            )}

            <nav ref={navRef} onScroll={saveScroll} className="flex-1 space-y-4 overflow-y-auto px-3 py-4" aria-label={t('layout.main_menu')}>
                {query && filteredSections.length === 0 && (
                    <p className="px-1 text-sm text-stone-500">{t('layout.search_no_results')}</p>
                )}
                {/* Mobile: a dense dark text list asks a first-time phone user to read and parse rows of
                    small type -- the same reason Home is giant buttons for a parent or teacher. Every
                    item here is the exact same permission-filtered data as the desktop list below, just
                    shown as big, colourful, icon-forward tiles instead. Nothing is removed, no accordion
                    to figure out -- everything open, one longer scroll. */}
                <div className="space-y-5 lg:hidden">
                    {filteredSections.filter((section) => section.items.length > 0).map((section, sIndex) => (
                        <div key={section.id ?? section.label}>
                            <p className="mb-2 px-1 text-xs font-semibold uppercase tracking-wider text-stone-500">{section.label}</p>
                            <div className="grid grid-cols-2 gap-3">
                                {section.items.map((item) => (
                                    <MobileNavTile key={item.label} item={item} tintIndex={sIndex} />
                                ))}
                            </div>
                        </div>
                    ))}
                </div>

                <div className="hidden lg:block lg:space-y-4">
                {collapsed
                    ? sections.map((section) => (
                        <div key={section.id ?? section.label} className="space-y-0.5 border-t border-forest-800 pt-2 first:border-t-0 first:pt-0">
                            {section.items.map((item) => (
                                <SidebarLink key={item.label} item={item} collapsed />
                            ))}
                        </div>
                    ))
                    : filteredSections.map((section, index) => {
                        const open = isOpen(section, index);
                        const panelId = `nav-section-${section.id}`;

                        return (
                            <div key={section.id ?? section.label}>
                                {collapsible ? (
                                    <button
                                        type="button"
                                        aria-expanded={open}
                                        aria-controls={panelId}
                                        onClick={() => save({ ...stored, [section.id]: !open })}
                                        className="flex min-h-[44px] w-full items-center justify-between rounded-lg px-3 text-left text-xs font-semibold uppercase tracking-wider text-stone-500 hover:bg-forest-900 hover:text-stone-300"
                                    >
                                        {section.label}
                                        <ChevronDownIcon className={`h-4 w-4 transition ${open ? 'rotate-180' : ''}`} aria-hidden="true" />
                                    </button>
                                ) : (
                                    <p className="px-3 pb-1 text-xs font-semibold uppercase tracking-wider text-stone-500">
                                        {section.label}
                                    </p>
                                )}
                                {open && (
                                    <div id={panelId} className="mt-0.5 space-y-0.5">
                                        {section.items.map((item) => (
                                            <SidebarLink key={item.label} item={item} />
                                        ))}
                                    </div>
                                )}
                            </div>
                        );
                    })}
                </div>
            </nav>
        </aside>
    );
}

export default function AuthenticatedLayout({ header, children }) {
    const { auth, locale } = usePage().props;
    const { t } = useTranslation();
    const user = auth.user;
    const [mobileOpen, setMobileOpen] = useState(false);
    // Parents and teachers get no navigation menu at all -- their own home page IS the menu (a handful
    // of big buttons), so there's nothing for a sidebar to hold and nothing for the hamburger to open.
    // Both are near-universally phone-only, first-time-computer users; a 12-item sidebar menu built for
    // an office admin is not something to ask either of them to learn.
    const noSidebar = auth.role === 'parent' || auth.role === 'teacher';
    // Moved into the profile menu rather than its own header icon -- on a narrow phone, back + forward +
    // the menu button + language + a theme icon + the bell + the profile button genuinely do not all fit
    // next to each other at once (see the header's own icon cluster below). Dark/light is the one of
    // those six that matters least to this app's parents and teachers, so it's the one that moved.
    const [isDark, setIsDark] = useState(false);
    useEffect(() => { setIsDark(getStoredTheme() === 'dark'); }, []);
    const toggleTheme = () => {
        const next = isDark ? 'light' : 'dark';
        setTheme(next);
        setIsDark(!isDark);
    };

    useEffect(() => {
        if (locale && i18n.language !== locale) {
            i18n.changeLanguage(locale);
        }
    }, [locale]);

    return (
        <div className="min-h-screen bg-stone-100 dark:bg-stone-900 lg:flex">
            {!noSidebar && (
                <Sidebar
                    mobileOpen={mobileOpen}
                    onClose={() => setMobileOpen(false)}
                />
            )}

            {!noSidebar && mobileOpen && (
                <div
                    className="fixed inset-0 z-20 bg-black/30 lg:hidden"
                    onClick={() => setMobileOpen(false)}
                />
            )}

            <div className="flex min-h-screen flex-1 flex-col">
                <header className="sticky top-0 z-10 flex h-16 shrink-0 items-center justify-between gap-2 border-b border-stone-200 bg-white px-3 sm:px-4 dark:border-stone-700 dark:bg-stone-800">
                    <div className="flex min-w-0 flex-1 items-center gap-0.5 sm:gap-2">
                        {/* A plain browser back/forward, right in the app's own header -- so moving between
                            pages never depends on knowing the phone browser's own back gesture or button. */}
                        <IconButton icon={ChevronLeftIcon} label={t('layout.go_back')} className="shrink-0" onClick={() => window.history.back()} />
                        {/* Forward only from a bit more width up: back + forward + the menu button + the
                            icon row on the right genuinely do not all fit a small phone at once -- back is
                            the one people actually reach for, so it alone stays on every width. */}
                        <IconButton icon={ChevronRightIcon} label={t('layout.go_forward')} className="hidden shrink-0 sm:inline-flex" onClick={() => window.history.forward()} />
                        {!noSidebar && (
                            <IconButton
                                icon={Bars3Icon}
                                label={t('layout.open_menu')}
                                className="lg:hidden shrink-0"
                                onClick={() => setMobileOpen(true)}
                            />
                        )}
                        <div className="min-w-0 flex-1 overflow-hidden [&>*]:truncate">{header}</div>
                    </div>

                    <div className="flex shrink-0 items-center gap-1 sm:gap-2">
                        {auth.branch?.name && (
                            <span className="hidden rounded-md bg-stone-100 px-3 py-1 text-xs font-medium text-stone-600 dark:bg-stone-700 dark:text-stone-300 sm:inline">
                                {auth.branch.name}
                            </span>
                        )}
                        <LanguageSwitcher />
                        <NotificationBell />
                        <Dropdown>
                            <Dropdown.Trigger>
                                <button
                                    type="button"
                                    className="inline-flex min-h-[48px] shrink-0 items-center gap-2 rounded-md px-2 py-1.5 text-sm font-medium text-stone-600 hover:text-stone-800 dark:text-stone-300 dark:hover:text-stone-100 md:min-h-0"
                                >
                                    <Avatar src={user.profile_photo_url} name={user.name} size={32} fallback="initials" />
                                    <span className="hidden text-right leading-tight sm:block">
                                        <span className="block">
                                            {user.name}
                                        </span>
                                        {user.title && (
                                            <span className="block text-xs text-stone-400">
                                                {user.title}
                                            </span>
                                        )}
                                    </span>
                                    <ChevronDownIcon className="h-4 w-4" />
                                </button>
                            </Dropdown.Trigger>
                            <Dropdown.Content>
                                <Dropdown.Link href={route('profile.edit')}>
                                    {t('layout.profile')}
                                </Dropdown.Link>
                                <button
                                    type="button"
                                    onClick={toggleTheme}
                                    className="flex min-h-[48px] w-full items-center gap-2 px-4 py-3 text-start text-base leading-5 text-stone-700 transition duration-150 ease-in-out hover:bg-stone-100 focus:bg-stone-100 focus:outline-none md:min-h-0 md:py-2 md:text-sm dark:text-stone-300 dark:hover:bg-stone-800 dark:focus:bg-stone-800"
                                >
                                    {isDark ? <SunIcon className="h-5 w-5 shrink-0" aria-hidden="true" /> : <MoonIcon className="h-5 w-5 shrink-0" aria-hidden="true" />}
                                    {isDark ? t('layout.light_theme') : t('layout.dim_theme')}
                                </button>
                                <Dropdown.Link
                                    href={route('logout')}
                                    method="post"
                                    as="button"
                                >
                                    {t('layout.log_out')}
                                </Dropdown.Link>
                            </Dropdown.Content>
                        </Dropdown>
                    </div>
                </header>

                <InvitationNotice />
                <main className="flex-1">{children}</main>
            </div>
        </div>
    );
}
