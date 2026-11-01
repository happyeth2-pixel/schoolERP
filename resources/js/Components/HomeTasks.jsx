import { ActionTile, FOCUS } from '@/Components/ui';
import { greetingKey } from '@/greeting';
import { pickTasks } from '@/homeTasks';
import { buildNavSections, enabledRoutes } from '@/navigation';
import { ChevronDownIcon, EllipsisHorizontalCircleIcon, QuestionMarkCircleIcon } from '@heroicons/react/24/outline';
import { Link, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

/** "What do you want to do today?" -- 3 to 6 large tiles for what this person does most, and everything
 *  else they are allowed to do one tap away under "More things you can do". */
export default function HomeTasks({ stats }) {
    const { t } = useTranslation();
    const { auth, notifications, today } = usePage().props;
    const permissions = auth.permissions ?? [];
    const has = (perm) => permissions.includes(perm);

    const navRoutes = enabledRoutes(buildNavSections({ role: auth.role, permissions, t }));
    const { persona, main, more } = pickTasks({ role: auth.role, has, navRoutes, stats, unread: notifications?.unreadCount ?? 0 });

    const badgeFor = (task) => {
        const b = task.badge?.({ stats, unread: notifications?.unreadCount ?? 0 });
        if (!b || !b.count) return null;
        return b.kind === 'waiting'
            ? { text: t('home.waiting', { count: b.count }), tone: 'warning' }
            : { text: t('home.new_count', { count: b.count }), tone: 'info' };
    };

    const tile = (task) => (
        <ActionTile
            key={task.key}
            icon={task.icon}
            href={task.href}
            title={t(`home.task.${task.key}.title`)}
            description={t(`home.task.${task.key}.desc`)}
            badge={badgeFor(task)}
        />
    );

    // the front desk gets a greeting and one more large tile, "More", that opens everything else they may do
    const frontdesk = persona === 'frontdesk';
    const greeting = t(greetingKey(today?.hour ?? 12), { name: auth.user.name });
    const openMore = () => { const el = document.getElementById('more-tasks'); if (el) el.open = true; };

    return (
        <section aria-labelledby="home-question" data-testid="home-tasks">
            {frontdesk ? (
                <>
                    <h1 id="home-question" className="text-2xl font-bold text-stone-900 dark:text-stone-100 sm:text-3xl" data-testid="greeting">{greeting}</h1>
                    <p className="mt-1 text-xl text-stone-700 dark:text-stone-300">{t('home.what_now')}</p>
                </>
            ) : (
                <h1 id="home-question" className="text-2xl font-bold text-stone-900 dark:text-stone-100 sm:text-3xl">{t('home.question')}</h1>
            )}

            {main.length === 0 && more.length === 0 && <p className="mt-4 text-base text-stone-600 dark:text-stone-400">{t('home.none')}</p>}

            {main.length > 0 && (
                <div className="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-3" data-testid="main-tiles">
                    {main.map(tile)}
                    {frontdesk && more.length > 0 && (
                        <ActionTile icon={EllipsisHorizontalCircleIcon} href="#more-tasks" onClick={openMore} title={t('home.more_tile')} description={t('home.more_tile_desc', { count: more.length })} data-testid="more-tile" />
                    )}
                </div>
            )}

            {more.length > 0 && (
                <details id="more-tasks" className="group mt-6 rounded-2xl border-2 border-stone-200 bg-white dark:border-stone-700 dark:bg-stone-800" data-testid="more-tasks">
                    <summary className={`flex min-h-[56px] cursor-pointer list-none items-center justify-between gap-3 rounded-2xl px-5 py-3 text-lg font-semibold text-stone-900 dark:text-stone-100 ${FOCUS}`}>
                        <span>{t('home.more')}<span className="ml-2 text-base font-normal text-stone-500">({more.length})</span></span>
                        <ChevronDownIcon className="h-6 w-6 transition group-open:rotate-180" aria-hidden="true" />
                    </summary>
                    <div className="border-t border-stone-200 p-5 dark:border-stone-700">
                        <p className="mb-4 text-base text-stone-600 dark:text-stone-400">{t('home.more_hint')}</p>
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">{more.map(tile)}</div>
                    </div>
                </details>
            )}

            <p className="mt-6">
                <Link href={route('help.index')} className={`inline-flex min-h-[48px] items-center gap-2 rounded-xl px-2 text-base font-medium text-brand-700 hover:underline dark:text-brand-300 ${FOCUS}`}>
                    <QuestionMarkCircleIcon className="h-6 w-6" aria-hidden="true" />
                    {t('home.help_link')}
                </Link>
            </p>
        </section>
    );
}
