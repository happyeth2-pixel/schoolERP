import ApplicationLogo from '@/Components/ApplicationLogo';
import Dropdown from '@/Components/Dropdown';
import { buildNavSections } from '@/navigation';
import { getStoredTheme, setTheme } from '@/theme';
import {
    Bars3Icon,
    BellIcon,
    ChevronDownIcon,
    MagnifyingGlassIcon,
    MoonIcon,
    SunIcon,
    UserCircleIcon,
} from '@heroicons/react/24/outline';
import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

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
        <button
            type="button"
            onClick={toggle}
            className="rounded-md p-2 text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-700"
            title={isDark ? 'Switch to light theme' : 'Switch to dim theme'}
        >
            {isDark ? <SunIcon className="h-5 w-5" /> : <MoonIcon className="h-5 w-5" />}
        </button>
    );
}

function NotificationBell() {
    const { notifications } = usePage().props;
    if (!notifications) return null;

    const markRead = (id) => router.post(route('notifications.read', id), {}, { preserveScroll: true });

    return (
        <Dropdown>
            <Dropdown.Trigger>
                <button type="button" className="relative rounded-md p-2 text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-700">
                    <BellIcon className="h-5 w-5" />
                    {notifications.unreadCount > 0 && (
                        <span className="absolute right-1 top-1 flex h-4 w-4 items-center justify-center rounded-full bg-red-500 text-[10px] font-medium text-white">
                            {notifications.unreadCount > 9 ? '9+' : notifications.unreadCount}
                        </span>
                    )}
                </button>
            </Dropdown.Trigger>
            <Dropdown.Content align="right" contentClasses="py-1 bg-white dark:bg-gray-700 w-80">
                <div className="max-h-80 overflow-y-auto">
                    {notifications.recent.length === 0 && (
                        <p className="p-4 text-sm text-gray-400">No notifications yet.</p>
                    )}
                    {notifications.recent.map((n) => (
                        <button
                            key={n.id}
                            onClick={() => !n.read_at && markRead(n.id)}
                            className={`block w-full px-4 py-3 text-left text-sm ${n.read_at ? '' : 'bg-indigo-50 dark:bg-indigo-900/20'} hover:bg-gray-100 dark:hover:bg-gray-700`}
                        >
                            <p className="font-medium text-gray-800 dark:text-gray-100">{n.title}</p>
                            <p className="text-gray-500">{n.body}</p>
                            <p className="mt-1 text-xs text-gray-400">{n.created_at}</p>
                        </button>
                    ))}
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
    const base = 'flex items-center gap-2.5 rounded-md px-3 py-2 text-sm transition';

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

function Sidebar({ mobileOpen, onClose }) {
    const { auth } = usePage().props;
    const sections = buildNavSections({
        role: auth.role,
        permissions: auth.permissions ?? [],
    });

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

            <nav className="flex-1 space-y-4 overflow-y-auto px-3 py-4">
                {sections.map((section) => (
                    <div key={section.label}>
                        <p className="px-3 pb-1 text-xs font-semibold uppercase tracking-wider text-gray-400">
                            {section.label}
                        </p>
                        <div className="space-y-0.5">
                            {section.items.map((item) => (
                                <SidebarLink key={item.label} item={item} />
                            ))}
                        </div>
                    </div>
                ))}
            </nav>
        </aside>
    );
}

export default function AuthenticatedLayout({ header, children }) {
    const { auth } = usePage().props;
    const user = auth.user;
    const [mobileOpen, setMobileOpen] = useState(false);

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
                    <div className="flex items-center gap-3">
                        <button
                            className="rounded-md p-2 text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-700 lg:hidden"
                            onClick={() => setMobileOpen(true)}
                        >
                            <Bars3Icon className="h-6 w-6" />
                        </button>
                        {header}
                    </div>

                    <div className="flex items-center gap-2">
                        <Link
                            href="#"
                            onClick={(e) => e.preventDefault()}
                            className="hidden rounded-md p-2 text-gray-400 sm:block"
                            title="Site-wide search — coming soon"
                        >
                            <MagnifyingGlassIcon className="h-5 w-5" />
                        </Link>
                        {auth.branch?.name && (
                            <span className="hidden rounded-full bg-gray-100 px-3 py-1 text-xs font-medium text-gray-600 dark:bg-gray-700 dark:text-gray-300 sm:inline">
                                {auth.branch.name}
                            </span>
                        )}
                        <ThemeToggle />
                        <NotificationBell />
                        <Dropdown>
                            <Dropdown.Trigger>
                                <button
                                    type="button"
                                    className="inline-flex items-center gap-2 rounded-md px-2 py-1.5 text-sm font-medium text-gray-600 hover:text-gray-800 dark:text-gray-300 dark:hover:text-gray-100"
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
                                    Profile
                                </Dropdown.Link>
                                <Dropdown.Link
                                    href={route('logout')}
                                    method="post"
                                    as="button"
                                >
                                    Log Out
                                </Dropdown.Link>
                            </Dropdown.Content>
                        </Dropdown>
                    </div>
                </header>

                <main className="flex-1">{children}</main>
            </div>
        </div>
    );
}
