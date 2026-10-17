import ApplicationLogo from '@/Components/ApplicationLogo';
import InvitationNotice from '@/Components/InvitationNotice';
import Dropdown from '@/Components/Dropdown';
import { IconButton } from '@/Components/ui';
import i18n from '@/i18n';
import { buildNavSections } from '@/navigation';
import { getStoredTheme, setTheme } from '@/theme';
import {
    Bars3Icon,
    BellIcon,
    ChevronDownIcon,
    LanguageIcon,
    MagnifyingGlassIcon,
    MoonIcon,
    SunIcon,
    UserCircleIcon,
} from '@heroicons/react/24/outline';
import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
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
                <IconButton icon={LanguageIcon} label={i18n.t('layout.language')} />
            </Dropdown.Trigger>
            <Dropdown.Content align="right">
                {enabled.map((code) => (
                    <button
                        key={code}
                        onClick={() => choose(code)}
                        className={`block min-h-[48px] w-full px-4 py-3 text-left text-base md:min-h-0 md:py-2 md:text-sm ${code === locale ? 'font-semibold text-indigo-600 dark:text-indigo-400' : 'text-gray-700 dark:text-gray-300'} hover:bg-gray-100 dark:hover:bg-gray-700`}
                    >
                        {LANGUAGE_NAMES[code] ?? code}
                    </button>
                ))}
            </Dropdown.Content>
        </Dropdown>
    );
}

function ThemeToggle() {
    const [isDark, setIsDark] = useState(false);

    useEffect(() => {
        setIsDark(getStoredTheme() === 'dark');
    }, []);

    const toggle = () => {
        const next = isDark ? 'light' : 'dark';
        setTheme(next);
        setIsDark(!isDark);
    };

    return (
        <IconButton
            icon={isDark ? SunIcon : MoonIcon}
            onClick={toggle}
            label={isDark ? 'Switch to light theme' : 'Switch to dim theme'}
        />
    );
}

function NotificationBell() {
    const { t } = useTranslation();
    const { notifications } = usePage().props;
    if (!notifications) return null;

    const markRead = (id) => router.post(route('notifications.read', id), {}, { preserveScroll: true });

    return (
        <Dropdown>
            <Dropdown.Trigger>
                <IconButton
                    icon={BellIcon}
                    label={notifications.unreadCount > 0 ? `${t('nav.notifications')} (${notifications.unreadCount})` : t('nav.notifications')}
                    badge={notifications.unreadCount > 0 ? (notifications.unreadCount > 9 ? '9+' : notifications.unreadCount) : null}
                />
            </Dropdown.Trigger>
            <Dropdown.Content align="right" contentClasses="py-1 bg-white dark:bg-gray-700 w-80">
                <div className="max-h-80 overflow-y-auto">
                    {notifications.recent.length === 0 && (
                        <p className="p-4 text-sm text-gray-400">No notifications yet.</p>
                    )}
                    {notifications.recent.map((n) => {
                        const Tag = n.action_url ? Link : 'button';
                        return (
                            <Tag
                                key={n.id}
                                {...(n.action_url ? { href: n.action_url } : {})}
                                onClick={() => !n.read_at && markRead(n.id)}
                                className={`block w-full px-4 py-3 text-left text-sm ${n.read_at ? '' : 'bg-indigo-50 dark:bg-indigo-900/20'} hover:bg-gray-100 dark:hover:bg-gray-700`}
                            >
                                <p className="font-medium text-gray-800 dark:text-gray-100">{n.title}</p>
                                <p className="text-gray-500">{n.body}</p>
                                <p className="mt-1 text-xs text-gray-400">{n.created_at}</p>
                            </Tag>
                        );
                    })}
                </div>
                <Link href={route('notifications.index')} className="block px-4 py-2 text-center text-sm text-indigo-600 hover:bg-gray-100 dark:text-indigo-400 dark:hover:bg-gray-700">
                    View all
                </Link>
            </Dropdown.Content>
        </Dropdown>
    );
}

function SidebarLink({ item }) {
    const Icon = item.icon;
    const base = 'flex min-h-[48px] items-center gap-3 rounded-md px-3 py-2 text-base transition md:min-h-[40px] md:gap-2.5 md:text-sm';

    if (!item.enabled) {
        return (
            <span
                className={`${base} cursor-not-allowed text-gray-400 dark:text-gray-600`}
                title="Coming in a later phase"
            >
                {Icon && <Icon className="h-5 w-5 shrink-0" />}
                {item.label}
            </span>
        );
    }

    const active = route().current(item.route);

    return (
        <Link
            href={route(item.route)}
            className={`${base} border-l-[3px] ${
                active
                    ? 'border-indigo-600 bg-indigo-50 font-semibold text-indigo-700 dark:border-indigo-400 dark:bg-indigo-900/40 dark:text-indigo-300'
                    : 'border-transparent text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800'
            }`}
        >
            {Icon && <Icon className="h-5 w-5 shrink-0" />}
            {item.label}
        </Link>
    );
}

const NAV_OPEN_KEY = 'nav-open-sections';

function readOpenState() {
    try {
        return JSON.parse(localStorage.getItem(NAV_OPEN_KEY) ?? '{}');
    } catch {
        return {};
    }
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

    const save = (next) => {
        setStored(next);
        try {
            localStorage.setItem(NAV_OPEN_KEY, JSON.stringify(next));
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

    const isOpen = (section, index) => !collapsible || (stored[section.id] ?? (index < 3 || section.id === activeId));

    return (
        <aside
            className={`fixed inset-y-0 left-0 z-30 flex w-64 transform flex-col border-r border-gray-200 bg-white transition-transform dark:border-gray-700 dark:bg-gray-800 lg:sticky lg:top-0 lg:h-screen lg:translate-x-0 ${
                mobileOpen ? 'translate-x-0' : '-translate-x-full'
            }`}
        >
            <div className="flex h-16 shrink-0 items-center gap-2 border-b border-gray-100 px-4 dark:border-gray-700">
                <Link href="/" onClick={onClose}>
                    <ApplicationLogo className="h-8 w-auto fill-current text-gray-800 dark:text-gray-200" />
                </Link>
                <span className="truncate text-sm font-semibold text-gray-700 dark:text-gray-200">
                    {auth.school?.name ?? 'School ERP'}
                </span>
            </div>

            <nav className="flex-1 space-y-2 overflow-y-auto px-3 py-4" aria-label={t('layout.main_menu')}>
                {sections.map((section, index) => {
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
                                    className="flex min-h-[44px] w-full items-center justify-between rounded-md px-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-700"
                                >
                                    {section.label}
                                    <ChevronDownIcon className={`h-4 w-4 transition ${open ? 'rotate-180' : ''}`} aria-hidden="true" />
                                </button>
                            ) : (
                                <p className="px-3 pb-1 text-xs font-semibold uppercase tracking-wider text-gray-400">
                                    {section.label}
                                </p>
                            )}
                            {open && (
                                <div id={panelId} className="space-y-0.5">
                                    {section.items.map((item) => (
                                        <SidebarLink key={item.label} item={item} />
                                    ))}
                                </div>
                            )}
                        </div>
                    );
                })}
            </nav>
        </aside>
    );
}

export default function AuthenticatedLayout({ header, children }) {
    const { auth, locale } = usePage().props;
    const { t } = useTranslation();
    const user = auth.user;
    const [mobileOpen, setMobileOpen] = useState(false);

    useEffect(() => {
        if (locale && i18n.language !== locale) {
            i18n.changeLanguage(locale);
        }
    }, [locale]);

    return (
        <div className="min-h-screen bg-slate-200 dark:bg-gray-900 lg:flex">
            <Sidebar
                mobileOpen={mobileOpen}
                onClose={() => setMobileOpen(false)}
            />

            {mobileOpen && (
                <div
                    className="fixed inset-0 z-20 bg-black/30 lg:hidden"
                    onClick={() => setMobileOpen(false)}
                />
            )}

            <div className="flex min-h-screen flex-1 flex-col">
                <header className="sticky top-0 z-10 flex h-16 shrink-0 items-center justify-between border-b border-gray-200 bg-white px-4 dark:border-gray-700 dark:bg-gray-800">
                    <div className="flex min-w-0 items-center gap-3">
                        <IconButton
                            icon={Bars3Icon}
                            label={t('layout.open_menu')}
                            className="lg:hidden"
                            onClick={() => setMobileOpen(true)}
                        />
                        {header}
                    </div>

                    <div className="flex items-center gap-2">
                        <Link
                            href="#"
                            onClick={(e) => e.preventDefault()}
                            className="hidden rounded-md p-2 text-gray-400 sm:block"
                            title={t('layout.search_placeholder')}
                        >
                            <MagnifyingGlassIcon className="h-5 w-5" />
                        </Link>
                        {auth.branch?.name && (
                            <span className="hidden rounded-full bg-gray-100 px-3 py-1 text-xs font-medium text-gray-600 dark:bg-gray-700 dark:text-gray-300 sm:inline">
                                {auth.branch.name}
                            </span>
                        )}
                        <LanguageSwitcher />
                        <ThemeToggle />
                        <NotificationBell />
                        <Dropdown>
                            <Dropdown.Trigger>
                                <button
                                    type="button"
                                    className="inline-flex min-h-[48px] items-center gap-2 rounded-md px-2 py-1.5 text-sm font-medium text-gray-600 hover:text-gray-800 dark:text-gray-300 dark:hover:text-gray-100 md:min-h-0"
                                >
                                    {user.profile_photo_url ? (
                                        <img src={user.profile_photo_url} alt="" className="h-8 w-8 rounded-full object-cover" />
                                    ) : (
                                        <UserCircleIcon className="h-8 w-8 text-gray-400" />
                                    )}
                                    <span className="hidden text-right leading-tight sm:block">
                                        <span className="block">
                                            {user.name}
                                        </span>
                                        {user.title && (
                                            <span className="block text-xs text-gray-400">
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
