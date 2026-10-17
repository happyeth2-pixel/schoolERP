import { STATUSES, statusLabelKey } from '@/Components/AttendanceParts';
import DateText from '@/Components/DateText';
import { ChildSwitcher, TodayStatus } from '@/Components/ParentParts';
import { Card, EmptyState, Field, Page, Select } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { ClipboardDocumentCheckIcon, UserGroupIcon } from '@heroicons/react/24/outline';
import { router, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

// A child's attendance for a parent: today first (big, in words), then how the year is going, then every recorded
// day as a card. Same data and the same protected route as before; the child switcher navigates to that child.

const monthLabel = (ym, locale) => new Date(`${ym}-01T00:00:00`).toLocaleDateString(locale ?? undefined, { month: 'long', year: 'numeric' });
const weekday = (iso, locale) => new Date(`${iso}T00:00:00`).toLocaleDateString(locale ?? undefined, { weekday: 'long' });

export default function Attendance({ children, student, months, period, records, totals, rate, today }) {
    const { t } = useTranslation();
    const { locale } = usePage().props;
    const go = (params) => router.get(route('family.attendance'), params, { preserveScroll: true });
    const title = t('parent.att_page_title');

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">{title}</h2>}>
            <Page title={title} width="default">
                {children.length === 0 && (
                    <Card><EmptyState icon={UserGroupIcon} title={t('parent.no_child_title')}>{t('parent.no_child_body')}</EmptyState></Card>
                )}

                {children.length > 0 && <ChildSwitcher children={children} value={student?.id} onChange={(id) => go({ student: id })} />}

                {student && (
                    <>
                        <Card as="section" aria-label={t('parent.att_title')} data-testid="today-card">
                            <p className="mb-3 text-lg font-semibold text-gray-900 dark:text-gray-100">{t('parent.att_today_for', { name: student.name })} · <DateText value={today?.date} /></p>
                            <TodayStatus status={today?.status ?? null} />
                        </Card>

                        <Card as="section" aria-label={t('parent.att_year')} data-testid="summary-card">
                            <p className="text-base text-gray-700 dark:text-gray-300">{period ? `${t('parent.att_year')} — ${monthLabel(period, locale)}` : t('parent.att_year')}</p>
                            <p className="text-5xl font-bold text-gray-900 dark:text-gray-100" data-testid="rate">{rate === null ? '—' : `${rate}%`} <span className="text-xl font-semibold text-gray-600 dark:text-gray-400">{t('parent.att_present_word')}</span></p>
                            {rate !== null && (
                                <div className="mt-3 h-3 overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700" role="progressbar" aria-valuenow={rate} aria-valuemin={0} aria-valuemax={100} aria-label={`${rate}%`}>
                                    <div className="h-3 rounded-full bg-emerald-600" style={{ width: `${rate}%` }} />
                                </div>
                            )}
                            <p className="mt-2 text-sm text-gray-600 dark:text-gray-400">{t('parent.att_rate_note', { count: totals.marked })}</p>
                            <ul className="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-5" data-testid="totals">
                                {STATUSES.map((s) => (
                                    <li key={s.value} className={`rounded-xl px-3 py-2 text-center ${s.chip}`}>
                                        <s.icon className="mx-auto h-6 w-6" aria-hidden="true" />
                                        <p className="text-2xl font-bold">{totals[s.value]}</p>
                                        <p className="text-sm font-semibold">{t(statusLabelKey(s.value))}</p>
                                    </li>
                                ))}
                            </ul>
                        </Card>

                        {months.length > 0 && (
                            <Card>
                                <Field id="month" label={t('parent.att_month')}>
                                    <Select value={period ?? ''} onChange={(e) => go({ student: student.id, month: e.target.value || undefined })}>
                                        <option value="">{t('parent.att_all_days')}</option>
                                        {months.map((m) => <option key={m} value={m}>{monthLabel(m, locale)}</option>)}
                                    </Select>
                                </Field>
                            </Card>
                        )}

                        <section aria-label={t('parent.att_days')}>
                            <h2 className="mb-3 text-xl font-bold text-gray-900 dark:text-gray-100">{t('parent.att_days')}</h2>
                            {records.length === 0 ? (
                                <Card><EmptyState icon={ClipboardDocumentCheckIcon} title={t('parent.att_none_title')}>{t('parent.att_none_body')}</EmptyState></Card>
                            ) : (
                                <ul className="space-y-3" data-testid="days">
                                    {records.map((r) => {
                                        const s = STATUSES.find((x) => x.value === r.status);
                                        return (
                                            <li key={r.id} className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border-2 border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800" data-testid="day-row" data-status={r.status}>
                                                <div>
                                                    <p className="text-lg font-semibold text-gray-900 dark:text-gray-100">{weekday(r.date, locale)}</p>
                                                    <p className="text-base text-gray-700 dark:text-gray-300"><DateText value={r.date} /></p>
                                                    {r.remarks && <p className="mt-1 text-base text-gray-700 dark:text-gray-300">{r.remarks}</p>}
                                                </div>
                                                <span className={`inline-flex items-center gap-2 rounded-full px-4 py-2 text-base font-bold ${s?.chip ?? ''}`}>
                                                    {s && <s.icon className="h-6 w-6" aria-hidden="true" />}{s ? t(statusLabelKey(r.status)) : r.status}
                                                </span>
                                            </li>
                                        );
                                    })}
                                </ul>
                            )}
                        </section>
                        <p className="text-sm text-gray-600 dark:text-gray-400">{t('parent.att_correction_note')}</p>
                    </>
                )}
            </Page>
        </AuthenticatedLayout>
    );
}
