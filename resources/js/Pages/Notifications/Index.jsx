import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';

export default function Index({ notificationList }) {
    const markRead = (id) => router.post(route('notifications.read', id));

    return (
        <AuthenticatedLayout
            header={<h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">Notifications</h2>}
        >
            <Head title="Notifications" />

            <div className="py-8">
                <div className="mx-auto max-w-2xl px-4 sm:px-6 lg:px-8">
                    <div className="space-y-2">
                        {notificationList.data.map((n) => (
                            <div
                                key={n.id}
                                className={`rounded-lg p-4 shadow-sm ${n.read_at ? 'bg-white dark:bg-gray-800' : 'bg-indigo-50 dark:bg-indigo-900/20'}`}
                            >
                                <div className="flex items-start justify-between gap-3">
                                    <div>
                                        <p className="text-sm font-medium text-gray-900 dark:text-gray-100">{n.title}</p>
                                        <p className="text-sm text-gray-500">{n.body}</p>
                                        <p className="mt-1 text-xs text-gray-400">{n.created_at}</p>
                                    </div>
                                    {!n.read_at && (
                                        <button className="shrink-0 text-xs text-indigo-600 hover:underline dark:text-indigo-400" onClick={() => markRead(n.id)}>
                                            Mark read
                                        </button>
                                    )}
                                </div>
                            </div>
                        ))}
                        {notificationList.data.length === 0 && (
                            <p className="text-center text-sm text-gray-400">No notifications yet.</p>
                        )}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
