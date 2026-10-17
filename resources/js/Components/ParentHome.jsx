import { ChildSwitcher, DayChip, TodayStatus, money } from '@/Components/ParentParts';
import { ActionTile, Alert, Button, Card, EmptyState, StatusBadge } from '@/Components/ui';
import { buildNavSections, enabledRoutes } from '@/navigation';
import { pickTasks } from '@/homeTasks';
import { BanknotesIcon, BellIcon, ClipboardDocumentCheckIcon, DocumentChartBarIcon, UserGroupIcon } from '@heroicons/react/24/outline';
import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

// The parent's home page. Not a menu of school-administration tasks: one child at a time, and the five things a
// parent comes for, in order -- the child, today's attendance, fees, the report card, messages from the school.
// With several children a row of big buttons switches whose information is shown (in place, nothing reloads).
// It only shows what the existing pages already know; every link goes to the same protected pages as before
// (each of which checks that the child is really this parent's).

const STORE = 'parent-child';
const remembered = () => { try { return Number(window.localStorage.getItem(STORE)) || null; } catch { return null; } };
const remember = (id) => { try { window.localStorage.setItem(STORE, String(id)); } catch { /* private window: fine */ } };

function SectionCard({ icon: Icon, title, children, testid }) {
    return (
        <Card as="section" aria-label={title} data-testid={testid}>
            <h2 className="mb-4 flex items-center gap-3 text-xl font-bold text-gray-900 dark:text-gray-100">
                <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-indigo-100 text-indigo-800 dark:bg-indigo-900/40 dark:text-indigo-200"><Icon className="h-6 w-6" aria-hidden="true" /></span>
                {title}
            </h2>
            {children}
        </Card>
    );
}

export default function ParentHome({ stats }) {
    const { t } = useTranslation();
    const { auth, notifications, locale } = usePage().props;
    const children = stats.children ?? [];
    const [childId, setChildId] = useState(() => { const r = remembered(); return children.some((c) => c.id === r) ? r : children[0]?.id; });
    const child = children.find((c) => c.id === childId) ?? children[0];
    const unread = notifications?.unreadCount ?? 0;
    const recent = notifications?.recent ?? [];

    const permissions = auth.permissions ?? [];
    const navRoutes = enabledRoutes(buildNavSections({ role: auth.role, permissions, t }));
    const { main, more } = pickTasks({ role: auth.role, has: (p) => permissions.includes(p), navRoutes, stats, unread });
    const extras = [...main, ...more].filter((x) => ['announcements', 'complaints'].includes(x.key));

    const choose = (id) => { setChildId(id); remember(id); };
    const day = (iso) => new Date(`${iso}T00:00:00`).toLocaleDateString(locale ?? undefined, { weekday: 'short', day: 'numeric', month: 'short' });

    if (children.length === 0) {
        return (
            <div className="space-y-6" data-testid="parent-home">
                <h1 className="text-2xl font-bold text-gray-900 dark:text-gray-100 sm:text-3xl">{t('parent.hello', { name: auth.user.name })}</h1>
                <Card><EmptyState icon={UserGroupIcon} title={t('parent.no_child_title')}>{t('parent.no_child_body')}</EmptyState></Card>
            </div>
        );
    }

    const hasBill = child.bills.length > 0;
    const owes = hasBill && Number(child.balance) > 0;
    const feeStatus = !hasBill ? null : owes ? (Number(child.invoice_paid) > 0 ? 'partially_paid' : 'unpaid') : 'paid';
    const paidPct = hasBill && Number(child.invoice_total) > 0 ? Math.min(100, Math.round((Number(child.invoice_paid) / Number(child.invoice_total)) * 100)) : 0;
    const unpaidBills = child.bills.filter((b) => Number(b.balance) > 0);

    return (
        <div className="space-y-6" data-testid="parent-home">
            <div>
                <h1 className="text-2xl font-bold text-gray-900 dark:text-gray-100 sm:text-3xl">{t('parent.hello', { name: auth.user.name })}</h1>
                {unread > 0 && (
                    <Link href={route('notifications.index')} className="mt-3 flex min-h-[56px] items-center gap-3 rounded-2xl border-2 border-sky-300 bg-sky-50 px-4 py-3 text-lg font-semibold text-sky-950 dark:border-sky-700 dark:bg-sky-900/25 dark:text-sky-50" data-testid="unread-banner">
                        <BellIcon className="h-7 w-7 shrink-0" aria-hidden="true" />
                        {t('parent.unread_banner', { count: unread })}
                    </Link>
                )}
            </div>

            <ChildSwitcher children={children} value={child.id} onChange={choose} />

            {/* 1. the child */}
            {children.length > 1 && (
                <p className="text-lg text-gray-700 dark:text-gray-300" data-testid="showing" aria-live="polite">{t('parent.showing', { name: child.name })}{child.class ? ` · ${child.class}` : ''}</p>
            )}

            {/* 2. today's attendance */}
            <SectionCard icon={ClipboardDocumentCheckIcon} title={t('parent.att_title')} testid="card-attendance">
                <TodayStatus status={child.today_status} />
                {child.recent_attendance.length > 0 && (
                    <div className="mt-4">
                        <p className="mb-2 text-base font-semibold text-gray-900 dark:text-gray-100">{t('parent.att_recent')}</p>
                        <ul className="flex flex-wrap gap-2" data-testid="recent-days">
                            {child.recent_attendance.map((r) => <DayChip key={r.date} date={r.date} status={r.status} label={day(r.date)} />)}
                        </ul>
                    </div>
                )}
                {child.attendance_rate !== null && <p className="mt-3 text-base text-gray-800 dark:text-gray-200" data-testid="att-rate">{t('parent.att_rate', { rate: child.attendance_rate })}</p>}
                <Button as={Link} href={route('family.attendance', { student: child.id })} variant="secondary" fullWidth className="mt-4">{t('parent.att_all')}</Button>
            </SectionCard>

            {/* 3. fees */}
            <SectionCard icon={BanknotesIcon} title={t('parent.fees_title')} testid="card-fees">
                {!hasBill ? (
                    <p className="text-lg text-gray-800 dark:text-gray-200">{t('parent.fees_none')}</p>
                ) : (
                    <>
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <p className="text-lg font-semibold text-gray-900 dark:text-gray-100">{owes ? t('parent.fees_left_label') : t('parent.fees_all_paid')}</p>
                            <StatusBadge status={feeStatus} label={t(`parent.fee_status_${feeStatus}`)} />
                        </div>
                        <p className="text-5xl font-bold text-gray-900 dark:text-gray-100" data-testid="fee-left">{money(child.balance)} <span className="text-2xl font-semibold text-gray-600 dark:text-gray-400">ETB</span></p>
                        <dl className="mt-4 grid grid-cols-2 gap-3 text-base">
                            <div className="rounded-xl bg-gray-50 p-3 dark:bg-gray-900/40"><dt className="text-sm text-gray-600 dark:text-gray-400">{t('parent.fees_total')}</dt><dd className="text-xl font-bold text-gray-900 dark:text-gray-100" data-testid="fee-total">{money(child.invoice_total)}</dd></div>
                            <div className="rounded-xl bg-gray-50 p-3 dark:bg-gray-900/40"><dt className="text-sm text-gray-600 dark:text-gray-400">{t('parent.fees_paid')}</dt><dd className="text-xl font-bold text-gray-900 dark:text-gray-100" data-testid="fee-paid">{money(child.invoice_paid)}</dd></div>
                        </dl>
                        <div className="mt-3 h-3 overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700" role="progressbar" aria-valuemin={0} aria-valuemax={100} aria-valuenow={paidPct} aria-label={t('parent.fees_progress', { pct: paidPct })}>
                            <div className="h-3 rounded-full bg-emerald-600" style={{ width: `${paidPct}%` }} />
                        </div>
                        {child.payments_waiting > 0 && (
                            <div className="mt-4"><Alert tone="info" title={t('parent.fees_waiting_title', { count: child.payments_waiting })}><span data-testid="fees-waiting">{t('parent.fees_waiting_body', { amount: money(child.payments_waiting_amount) })}</span></Alert></div>
                        )}
                        {child.bills.length > 1 && (
                            <ul className="mt-4 divide-y divide-gray-200 dark:divide-gray-700" data-testid="bills">
                                {child.bills.map((b) => (
                                    <li key={b.id} className="flex flex-wrap items-center justify-between gap-2 py-3">
                                        <span className="text-lg font-semibold text-gray-900 dark:text-gray-100">{b.label}</span>
                                        <span className="flex items-center gap-3">
                                            <span className="text-base text-gray-800 dark:text-gray-200">{Number(b.balance) > 0 ? t('parent.bill_left', { amount: money(b.balance) }) : t('parent.bill_paid')}</span>
                                            <Button as={Link} href={route('invoices.show', b.id)} variant={Number(b.balance) > 0 ? 'primary' : 'secondary'} size="md">{Number(b.balance) > 0 ? t('parent.bill_pay') : t('parent.bill_see')}</Button>
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                        {child.bills.length === 1 && (
                            <Button as={Link} href={route('invoices.show', child.bills[0].id)} fullWidth className="mt-4" variant={owes ? 'primary' : 'secondary'}>{owes ? t('parent.bill_pay_one') : t('parent.bill_see_one')}</Button>
                        )}
                        {child.bills.length > 1 && unpaidBills.length === 0 && <p className="mt-2 text-base text-gray-700 dark:text-gray-300">{t('parent.fees_all_paid_note')}</p>}
                    </>
                )}
            </SectionCard>

            {/* 4. report card */}
            <SectionCard icon={DocumentChartBarIcon} title={t('parent.report_title')} testid="card-report">
                <p className="mb-4 text-base text-gray-800 dark:text-gray-200">{t('parent.report_body', { name: child.first_name ?? child.name })}</p>
                <div className="flex flex-col gap-3 sm:flex-row">
                    <Button as={Link} href={route('report-card.show', child.id)} fullWidth icon={DocumentChartBarIcon}>{t('parent.report_view')}</Button>
                    <Button as="a" href={route('report-card.pdf', child.id)} variant="secondary" fullWidth>{t('parent.report_download')}</Button>
                </div>
            </SectionCard>

            {/* 5. messages */}
            <SectionCard icon={BellIcon} title={t('parent.msg_title')} testid="card-messages">
                {recent.length === 0 ? (
                    <p className="text-lg text-gray-800 dark:text-gray-200">{t('parent.msg_none')}</p>
                ) : (
                    <ul className="space-y-3" data-testid="recent-messages">
                        {recent.slice(0, 3).map((n) => (
                            <li key={n.id} className={`rounded-xl border-2 p-3 ${n.read_at ? 'border-gray-200 dark:border-gray-700' : 'border-sky-400 bg-sky-50 dark:border-sky-700 dark:bg-sky-900/20'}`} data-unread={!n.read_at}>
                                {!n.read_at && <StatusBadge tone="info" label={t('parent.msg_new')} className="mb-1" />}
                                <p className="text-base font-semibold text-gray-900 dark:text-gray-100">{n.title}</p>
                                <p className="text-base text-gray-700 dark:text-gray-300">{n.body}</p>
                                <p className="mt-1 text-sm text-gray-600 dark:text-gray-400">{n.created_at}</p>
                                {n.action_url && <Link href={n.action_url} className="-ml-[14px] mt-1 block text-sm font-semibold text-indigo-700 hover:underline dark:text-indigo-300" data-testid="notification-action">{t('parent.msg_open')}</Link>}
                            </li>
                        ))}
                    </ul>
                )}
                <div className="mt-4 flex flex-col gap-3 sm:flex-row">
                    <Button as={Link} href={route('notifications.index')} fullWidth variant={unread > 0 ? 'primary' : 'secondary'}>{unread > 0 ? t('parent.msg_read_new', { count: unread }) : t('parent.msg_read_all')}</Button>
                    {unread > 0 && <Button variant="secondary" fullWidth onClick={() => router.post(route('notifications.read-all'), {}, { preserveScroll: true })}>{t('parent.msg_mark_all')}</Button>}
                </div>
            </SectionCard>

            {extras.length > 0 && (
                <section aria-label={t('parent.more_title')}>
                    <h2 className="mb-3 text-xl font-bold text-gray-900 dark:text-gray-100">{t('parent.more_title')}</h2>
                    <div className="grid gap-4 sm:grid-cols-2">
                        {extras.map((x) => <ActionTile key={x.key} icon={x.icon} href={x.href} title={t(`home.task.${x.key}.title`)} description={t(`home.task.${x.key}.desc`)} />)}
                    </div>
                </section>
            )}
        </div>
    );
}
