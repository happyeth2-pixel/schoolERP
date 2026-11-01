import { money } from '@/Components/ParentParts';
import { ActionTile, Card, EmptyState } from '@/Components/ui';
import {
    AcademicCapIcon,
    BanknotesIcon,
    BellIcon,
    BookOpenIcon,
    CalendarDaysIcon,
    ChatBubbleLeftRightIcon,
    ClipboardDocumentCheckIcon,
    DocumentChartBarIcon,
    MegaphoneIcon,
    QuestionMarkCircleIcon,
    UserGroupIcon,
} from '@heroicons/react/24/outline';
import { Link, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

// A parent's home: big buttons, nothing else. Everything this used to show inline (today's attendance,
// the fee breakdown, message previews) is a tap away on its own page instead -- a parent picks what they
// came for first, then sees the detail for it, one thing at a time, rather than scrolling past four
// other topics to find it.

export default function ParentHome({ stats }) {
    const { t } = useTranslation();
    const { auth, notifications } = usePage().props;
    const children = stats.children ?? [];
    const unread = notifications?.unreadCount ?? 0;
    const totalOwed = children.reduce((sum, c) => sum + Math.max(0, Number(c.balance) || 0), 0);

    if (children.length === 0) {
        return (
            <div className="space-y-6" data-testid="parent-home">
                <h1 className="text-2xl font-bold text-stone-900 dark:text-stone-100 sm:text-3xl">{t('parent.hello', { name: auth.user.name })}</h1>
                <Card><EmptyState icon={UserGroupIcon} title={t('parent.no_child_title')}>{t('parent.no_child_body')}</EmptyState></Card>
            </div>
        );
    }

    return (
        <div className="space-y-6" data-testid="parent-home">
            {totalOwed > 0 && (
                <Link
                    href={route('family.payments')}
                    className="flex min-h-[64px] items-center gap-3 rounded-2xl border-2 border-red-300 bg-red-50 px-4 py-3 text-lg font-bold text-red-950 dark:border-red-700 dark:bg-red-900/25 dark:text-red-50"
                    data-testid="due-banner"
                >
                    <BanknotesIcon className="h-8 w-8 shrink-0" aria-hidden="true" />
                    <span>{t('parent.due_banner', { amount: money(totalOwed) })}</span>
                </Link>
            )}

            <ul className="flex flex-wrap gap-3" data-testid="child-roster">
                {children.map((c, i) => (
                    <li key={c.id} className="flex items-center gap-2 rounded-xl border-2 border-stone-300 bg-white px-4 py-2.5 dark:border-stone-600 dark:bg-stone-800">
                        <span className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-base font-bold text-white ${['bg-brand-600', 'bg-sky-700', 'bg-emerald-700', 'bg-amber-700', 'bg-rose-700'][i % 5]}`} aria-hidden="true">
                            {c.name?.trim()?.[0]?.toUpperCase() ?? '?'}
                        </span>
                        <span>
                            <span className="block text-base font-bold leading-tight text-stone-900 dark:text-stone-100">{c.first_name ?? c.name}</span>
                            {c.class && <span className="block text-sm font-semibold leading-tight text-brand-700 dark:text-brand-400">{c.class}</span>}
                        </span>
                    </li>
                ))}
            </ul>

            <div className="grid gap-4 sm:grid-cols-2">
                <ActionTile
                    icon={DocumentChartBarIcon}
                    title={t('parent.report_title')}
                    href={route('family.report-cards')}
                    tint="bg-violet-700 text-white"
                />
                <ActionTile
                    icon={BanknotesIcon}
                    title={t('parent.fees_title')}
                    href={route('family.payments')}
                    tint="bg-emerald-700 text-white"
                    badge={totalOwed > 0 ? { text: t('parent.fees_left_label'), tone: 'danger' } : undefined}
                />
                <ActionTile
                    icon={ClipboardDocumentCheckIcon}
                    title={t('parent.att_button')}
                    href={route('family.attendance')}
                    tint="bg-sky-700 text-white"
                />
                <ActionTile
                    icon={BookOpenIcon}
                    title={t('nav.homework')}
                    href={route('family.homework')}
                    tint="bg-teal-700 text-white"
                />
                <ActionTile
                    icon={BellIcon}
                    title={t('parent.msg_button')}
                    href={route('messages.index')}
                    tint="bg-amber-700 text-white"
                    badge={unread > 0 ? { text: String(unread), tone: 'danger' } : undefined}
                />
                <ActionTile icon={AcademicCapIcon} title={t('nav.results', 'Results')} href={route('family.results')} tint="bg-fuchsia-700 text-white" />
                <ActionTile icon={CalendarDaysIcon} title={t('nav.family_timetable', 'Timetable')} href={route('family.timetable')} tint="bg-indigo-700 text-white" />
                <ActionTile icon={MegaphoneIcon} title={t('nav.announcements', 'Announcements')} href={route('announcements.index')} tint="bg-orange-700 text-white" />
                <ActionTile icon={CalendarDaysIcon} title={t('nav.calendar', 'Calendar')} href={route('calendar.index')} tint="bg-cyan-700 text-white" />
                <ActionTile icon={ChatBubbleLeftRightIcon} title={t('nav.complaints', 'Complaints')} href={route('complaints.index')} tint="bg-rose-700 text-white" />
                <ActionTile icon={QuestionMarkCircleIcon} title={t('nav.help', 'Help')} href={route('help.index')} tint="bg-stone-600 text-white" />
            </div>
        </div>
    );
}
