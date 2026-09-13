import ApplicationLogo from '@/Components/ApplicationLogo';
import Dropdown from '@/Components/Dropdown';
import { buildNavSections } from '@/navigation';
import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

function NotificationBell() {
    const { notifications } = usePage().props;
    if (!notifications) return null;

    const markRead = (id) => router.post(route('notifications.read', id), {}, { preserveScroll: true });

    return (
        <Dropdown>
            <Dropdown.Trigger>
                <button type="button" className="relative rounded-md p-2 text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-700">
                    <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M15 17h5l-1.4-1.4A2 2 0 0118 14.2V11a6 6 0 10-12 0v3.2a2 2 0 01-.6 1.4L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                    </svg>
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
    const base =
        'block rounded-md px-3 py-2 text-sm font-medium transition';

    if (!item.enabled) {
        return (
            <span
                className={`${base} cursor-not-allowed text-gray-400 dark:text-gray-600`}
                title="Coming in a later phase"
            >
                {item.label}
            </span>
        );
    }

    const active = route().current(item.route);

    return (
        <Link
            href={route(item.route)}
            className={`${base} ${
                active
                    ? 'bg-indigo-50 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300'
                    : 'text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800'
            }`}
        >
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
            className={`fixed inset-y-0 left-0 z-30 w-64 transform border-r border-gray-200 bg-white transition-transform dark:border-gray-700 dark:bg-gray-800 lg:static lg:translate-x-0 ${
                mobileOpen ? 'translate-x-0' : '-translate-x-full'
            }`}
        >
            <div className="flex h-16 items-center gap-2 border-b border-gray-100 px-4 dark:border-gray-700">
                <Link href="/" onClick={onClose}>
                    <ApplicationLogo className="h-8 w-auto fill-current text-gray-800 dark:text-gray-200" />
                </Link>
                <span className="truncate text-sm font-semibold text-gray-700 dark:text-gray-200">
                    {auth.school?.name ?? 'School ERP'}
                </span>
            </div>

            <nav className="space-y-4 overflow-y-auto px-3 py-4">
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
        <div className="min-h-screen bg-gray-100 dark:bg-gray-900 lg:flex">
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
                <header className="flex h-16 items-center justify-between border-b border-gray-200 bg-white px-4 dark:border-gray-700 dark:bg-gray-800">
                    <div className="flex items-center gap-3">
                        <button
                            className="rounded-md p-2 text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-700 lg:hidden"
                            onClick={() => setMobileOpen(true)}
                        >
                            <svg
                                className="h-6 w-6"
                                stroke="currentColor"
                                fill="none"
                                viewBox="0 0 24 24"
                            >
                                <path
                                    strokeLinecap="round"
                                    strokeLinejoin="round"
                                    strokeWidth="2"
                                    d="M4 6h16M4 12h16M4 18h16"
                                />
                            </svg>
                        </button>
                        {header}
                    </div>

                    <div className="flex items-center gap-4">
                        {auth.branch?.name && (
                            <span className="hidden rounded-full bg-gray-100 px-3 py-1 text-xs font-medium text-gray-600 dark:bg-gray-700 dark:text-gray-300 sm:inline">
                                {auth.branch.name}
                            </span>
                        )}
                        <NotificationBell />
                        <Dropdown>
                            <Dropdown.Trigger>
                                <button
                                    type="button"
                                    className="inline-flex items-center gap-2 rounded-md px-3 py-2 text-sm font-medium text-gray-600 hover:text-gray-800 dark:text-gray-300 dark:hover:text-gray-100"
                                >
                                    <span className="text-right leading-tight">
                                        <span className="block">
                                            {user.name}
                                        </span>
                                        {user.title && (
                                            <span className="block text-xs text-gray-400">
                                                {user.title}
                                            </span>
                                        )}
                                    </span>
                                    <svg
                                        className="h-4 w-4"
                                        viewBox="0 0 20 20"
                                        fill="currentColor"
                                    >
                                        <path
                                            fillRule="evenodd"
                                            d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                                            clipRule="evenodd"
                                        />
                                    </svg>
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
