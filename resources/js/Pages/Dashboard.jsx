import Card3D from '@/Components/Card3D';
import DonutChart from '@/Components/charts/DonutChart';
import HomeTasks from '@/Components/HomeTasks';
import TeacherHome from '@/Components/TeacherHome';
import ParentHome from '@/Components/ParentHome';
import StudentDemographics from '@/Components/StudentDemographics';
import TodayHeader from '@/Components/TodayHeader';
import GradientStatTile from '@/Components/GradientStatTile';
import Meter from '@/Components/Meter';
import { Disclosure } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import {
    AcademicCapIcon,
    ArchiveBoxIcon,
    ComputerDesktopIcon,
    ArchiveBoxXMarkIcon,
    BanknotesIcon,
    BriefcaseIcon,
    CalendarIcon,
    ClipboardDocumentCheckIcon,
    ClockIcon,
    CurrencyDollarIcon,
    ExclamationTriangleIcon,
    HeartIcon,
    StarIcon,
    UserGroupIcon,
} from '@heroicons/react/24/outline';
import { Head, Link, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

function glowFor(value, thresholds = { good: 90, warning: 75 }) {
    if (value === null || value === undefined) return 'gray';
    if (value >= thresholds.good) return 'green';
    if (value >= thresholds.warning) return 'amber';
    return 'red';
}

// Colours match the app's own status meaning everywhere else (ui/tokens.js TONE): emerald = good,
// amber = warning, red = bad. "Excused"/"waived"/"sick" are neither good nor bad, just a distinct
// category, so they get the brand and info hues instead of a second, unrelated purple each.
function attendanceSegments(breakdown, t) {
    if (!breakdown) return [];
    return [
        { label: t('attendance.status_present'), value: breakdown.present, color: '#10b981' },
        { label: t('attendance.status_late'), value: breakdown.late, color: '#f59e0b' },
        { label: t('attendance.status_absent'), value: breakdown.absent, color: '#ef4444' },
        { label: t('attendance.status_excused'), value: breakdown.excused, color: '#0ea5e9' },
        { label: t('attendance.status_sick'), value: breakdown.sick, color: '#7a3420' },
    ];
}

function feeSegments({ paid, outstanding, waived, refunded }, t) {
    const segments = [
        { label: t('status.paid'), value: paid ?? 0, color: '#10b981' },
        { label: t('status.outstanding'), value: outstanding ?? 0, color: '#ef4444' },
    ];
    if (waived > 0) segments.push({ label: t('status.waived'), value: waived, color: '#7a3420' });
    if (refunded > 0) segments.push({ label: t('status.refunded'), value: refunded, color: '#f59e0b' });
    return segments;
}

function inventorySegments(inv, t) {
    if (!inv) return [];
    const ok = Math.max(0, inv.total_items - inv.low_stock - inv.out_of_stock);
    return [
        { label: t('dashboard.inventory_ok_stock'), value: ok, color: '#10b981' },
        { label: t('dashboard.inventory_low_stock'), value: inv.low_stock, color: '#f59e0b' },
        { label: t('dashboard.inventory_out_of_stock'), value: inv.out_of_stock, color: '#ef4444' },
    ];
}

/** Everything genuinely urgent across Inventory/Procurement/HR, in one place -- what decides whether
 *  that module's section opens by itself or stays folded (same "the section holding something relevant
 *  opens itself" rule the sidebar already uses). Never hides a number, just whether it starts expanded. */
function needsAttention(...values) {
    return values.some((v) => typeof v === 'number' && v > 0);
}

function OrgDashboard({ stats }) {
    const { t } = useTranslation();

    const inventoryOpen = needsAttention(stats.inventory?.low_stock, stats.inventory?.out_of_stock, stats.requisitions?.pending_approvals, stats.requisitions?.my_pending, stats.assets?.under_repair);
    const procurementOpen = needsAttention(stats.procurement?.pending_approvals, stats.procurement?.my_pending);
    const hrOpen = needsAttention(stats.hr?.contracts_expiring_soon, stats.hr?.pending_leave_requests);

    return (
        <div className="space-y-6">
            {/* Always visible: the handful of numbers that matter every single day, for every org-wide role. */}
            <div className="grid gap-4 sm:grid-cols-2">
                <GradientStatTile icon={UserGroupIcon} label={t('dashboard.total_students')} value={stats.total_students} color="blue" />
                <GradientStatTile icon={AcademicCapIcon} label={t('dashboard.total_teachers')} value={stats.total_teachers} color="purple" />
                <Card3D glow="gray" className="p-5 sm:col-span-2">
                    <h3 className="mb-3 text-sm font-semibold text-stone-500 dark:text-stone-400">{t('dashboard.attendance_breakdown')}</h3>
                    {stats.today_attendance_breakdown ? (
                        <DonutChart
                            segments={attendanceSegments(stats.today_attendance_breakdown, t)}
                            centerValue={`${stats.today_attendance_rate}%`}
                            centerLabel={t('dashboard.today_attendance')}
                        />
                    ) : (
                        <Meter bare label={t('dashboard.today_attendance')} value={stats.today_attendance_rate} thresholds={{ good: 90, warning: 75 }} emptyLabel={t('dashboard.not_marked_today')} />
                    )}
                </Card3D>
                {stats.fees_total !== null && stats.fees_total !== undefined && (
                <Card3D glow="gray" className="p-5 sm:col-span-2">
                    <h3 className="mb-3 text-sm font-semibold text-stone-500 dark:text-stone-400">{t('dashboard.fees_breakdown')}</h3>
                    <DonutChart
                        segments={feeSegments({ paid: stats.fees_paid, outstanding: stats.outstanding_fees, waived: stats.fees_waived, refunded: stats.fees_refunded }, t)}
                        centerValue={`${stats.fee_collection_rate ?? 0}%`}
                        centerLabel={t('dashboard.fee_collection_rate')}
                        emptyLabel="No invoices yet"
                    />
                </Card3D>
                )}
                {stats.pending_payments_count !== null && stats.pending_payments_count !== undefined && (
                <GradientStatTile icon={ClipboardDocumentCheckIcon} label={t('dashboard.payment_proofs_pending')} value={stats.pending_payments_count} color="amber" />
                )}
                {stats.medical && (
                    <Link className="block" href={route('medical-records.index')}>
                        <GradientStatTile
                            icon={HeartIcon}
                            label={t('dashboard.medical_on_file')}
                            value={t('dashboard.medical_on_file_value', { onFile: stats.medical.on_file, total: stats.medical.total_enrolled })}
                            color="red"
                        />
                    </Link>
                )}
            </div>

            {/* Everything else: real numbers, just not what this role needs to see on every visit -- folded
                by module, opening itself only when something in it actually needs attention. */}
            {(stats.inventory || stats.requisitions || stats.assets) && (
                <Disclosure title={t('section.inventory')} defaultOpen={inventoryOpen}>
                    <div className="grid gap-4 sm:grid-cols-2">
                        {stats.inventory && (
                            <>
                                <Link className="block" href={route('inventory-items.index')}><GradientStatTile icon={ArchiveBoxIcon} label={t('dashboard.inventory_total_items')} value={stats.inventory.total_items} color="blue" /></Link>
                                <Link className="block" href={route('inventory-items.index')}><GradientStatTile icon={ExclamationTriangleIcon} label={t('dashboard.inventory_low_stock')} value={stats.inventory.low_stock} color={stats.inventory.low_stock > 0 ? 'amber' : 'green'} /></Link>
                                <Link className="block" href={route('inventory-items.index')}><GradientStatTile icon={ArchiveBoxXMarkIcon} label={t('dashboard.inventory_out_of_stock')} value={stats.inventory.out_of_stock} color={stats.inventory.out_of_stock > 0 ? 'red' : 'green'} /></Link>
                                <Card3D glow="blue" className="p-5 sm:col-span-2">
                                    <h3 className="mb-3 text-sm font-semibold text-stone-500 dark:text-stone-400">{t('dashboard.inventory_breakdown')}</h3>
                                    <DonutChart segments={inventorySegments(stats.inventory, t)} centerValue={stats.inventory.total_items} centerLabel={t('dashboard.inventory_total_items')} />
                                </Card3D>
                            </>
                        )}
                        {stats.requisitions && (
                            <>
                                {stats.requisitions.pending_approvals !== null && (
                                    <Link className="block" href={route('requisitions.index', { status: 'submitted' })}><GradientStatTile icon={ClipboardDocumentCheckIcon} label={t('dashboard.pending_approvals')} value={stats.requisitions.pending_approvals} color={stats.requisitions.pending_approvals > 0 ? 'amber' : 'green'} /></Link>
                                )}
                                {stats.requisitions.my_pending > 0 && (
                                    <Link className="block" href={route('requisitions.index')}><GradientStatTile icon={ClipboardDocumentCheckIcon} label={t('dashboard.my_pending_requisitions')} value={stats.requisitions.my_pending} color="blue" /></Link>
                                )}
                            </>
                        )}
                        {stats.assets && (
                            <>
                                <Link className="block" href={route('assets.index')}><GradientStatTile icon={ComputerDesktopIcon} label={t('dashboard.total_assets')} value={stats.assets.total} color="blue" /></Link>
                                {stats.assets.under_repair > 0 && (
                                    <Link className="block" href={route('assets.index', { status: 'under_repair' })}><GradientStatTile icon={ComputerDesktopIcon} label={t('dashboard.assets_under_repair')} value={stats.assets.under_repair} color="amber" /></Link>
                                )}
                            </>
                        )}
                    </div>
                </Disclosure>
            )}

            {stats.procurement && (
                <Disclosure title={t('section.procurement')} defaultOpen={procurementOpen}>
                    <div className="grid gap-4 sm:grid-cols-2">
                        {stats.procurement.pending_approvals !== null && (
                            <Link className="block" href={route('purchase-requests.index', { status: 'submitted' })}><GradientStatTile icon={ClipboardDocumentCheckIcon} label={t('dashboard.procurement_pending_approvals')} value={stats.procurement.pending_approvals} color={stats.procurement.pending_approvals > 0 ? 'amber' : 'green'} /></Link>
                        )}
                        {stats.procurement.my_pending > 0 && (
                            <Link className="block" href={route('purchase-requests.index')}><GradientStatTile icon={ClipboardDocumentCheckIcon} label={t('dashboard.my_pending_purchase_requests')} value={stats.procurement.my_pending} color="blue" /></Link>
                        )}
                    </div>
                </Disclosure>
            )}

            {stats.hr && (
                <Disclosure title={t('section.hr')} defaultOpen={hrOpen}>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Link className="block" href={route('employees.index')}><GradientStatTile icon={BriefcaseIcon} label={t('dashboard.total_employees')} value={stats.hr.total_employees} color="blue" /></Link>
                        {stats.hr.contracts_expiring_soon !== null && stats.hr.contracts_expiring_soon > 0 && (
                            <Link className="block" href={route('employees.index')}><GradientStatTile icon={ClockIcon} label={t('dashboard.contracts_expiring_soon')} value={stats.hr.contracts_expiring_soon} color="amber" /></Link>
                        )}
                        {stats.hr.attendance_today !== null && (
                            <Card3D glow={glowFor(stats.hr.attendance_today.rate, { good: 90, warning: 75 })} className="p-5">
                                <Link href={route('staff-attendance.index')}>
                                    <Meter
                                        bare
                                        label={t('dashboard.staff_attendance_today')}
                                        value={stats.hr.attendance_today.rate}
                                        thresholds={{ good: 90, warning: 75 }}
                                        sublabel={stats.hr.attendance_today.marked > 0 ? `${stats.hr.attendance_today.marked} ${t('dashboard.employees_marked')}` : undefined}
                                        emptyLabel={t('dashboard.not_marked_today')}
                                    />
                                </Link>
                            </Card3D>
                        )}
                        {stats.hr.pending_leave_requests !== null && (
                            <Link className="block" href={route('leave-requests.pending')}><GradientStatTile icon={CalendarIcon} label={t('dashboard.pending_leave_requests')} value={stats.hr.pending_leave_requests} color={stats.hr.pending_leave_requests > 0 ? 'amber' : 'green'} /></Link>
                        )}
                        {stats.hr.reviews_finalized_this_year !== null && (
                            <Link className="block" href={route('performance-cycles.index')}><GradientStatTile icon={StarIcon} label={t('dashboard.reviews_finalized_this_year')} value={stats.hr.reviews_finalized_this_year} color="purple" /></Link>
                        )}
                        {stats.hr.latest_payroll_run !== null && (
                            <Link className="block" href={route('payroll-runs.index')}>
                                <GradientStatTile icon={BanknotesIcon} label={t('dashboard.latest_payroll_run_x', { period: stats.hr.latest_payroll_run.label })} value={t(`payroll.status_${stats.hr.latest_payroll_run.status}`)} color={stats.hr.latest_payroll_run.status === 'finalized' ? 'green' : 'amber'} />
                            </Link>
                        )}
                    </div>
                </Disclosure>
            )}
        </div>
    );
}

function AccountantDashboard({ stats }) {
    const { t } = useTranslation();

    return (
        <div className="grid gap-4 sm:grid-cols-2">
            <GradientStatTile icon={BanknotesIcon} label={t('dashboard.fees_collected_today')} value={Number(stats.collected_today).toLocaleString()} color="green" />
            <GradientStatTile icon={CurrencyDollarIcon} label={t('dashboard.fees_collected_month')} value={Number(stats.collected_this_month).toLocaleString()} color="blue" />
            <Card3D glow="gray" className="p-5 sm:col-span-2">
                <h3 className="mb-3 text-sm font-semibold text-stone-500 dark:text-stone-400">{t('dashboard.fees_breakdown')}</h3>
                <DonutChart
                    segments={feeSegments({ paid: stats.fees_paid, outstanding: stats.outstanding_balance, waived: stats.fees_waived }, t)}
                    centerValue={Number(stats.fees_total).toLocaleString()}
                    centerLabel={t('dashboard.school_fees')}
                    emptyLabel="No invoices yet"
                />
            </Card3D>
            <GradientStatTile icon={ClipboardDocumentCheckIcon} label={t('dashboard.payment_proofs_review')} value={stats.pending_payments_count} color="amber" />
            {stats.inventory && (
                <>
                    <Link className="block" href={route('inventory-items.index')}><GradientStatTile icon={ArchiveBoxIcon} label={t('dashboard.inventory_total_items')} value={stats.inventory.total_items} color="blue" /></Link>
                    <Link className="block" href={route('inventory-items.index')}><GradientStatTile icon={ExclamationTriangleIcon} label={t('dashboard.inventory_low_stock')} value={stats.inventory.low_stock} color={stats.inventory.low_stock > 0 ? 'amber' : 'green'} /></Link>
                    <Card3D glow="blue" className="p-5 sm:col-span-2">
                        <h3 className="mb-3 text-sm font-semibold text-stone-500 dark:text-stone-400">{t('dashboard.inventory_breakdown')}</h3>
                        <DonutChart segments={inventorySegments(stats.inventory, t)} centerValue={stats.inventory.total_items} centerLabel={t('dashboard.inventory_total_items')} />
                    </Card3D>
                </>
            )}
            {stats.requisitions && stats.requisitions.pending_approvals !== null && (
                <Link className="block" href={route('requisitions.index', { status: 'submitted' })}><GradientStatTile icon={ClipboardDocumentCheckIcon} label={t('dashboard.pending_approvals')} value={stats.requisitions.pending_approvals} color={stats.requisitions.pending_approvals > 0 ? 'amber' : 'green'} /></Link>
            )}
        </div>
    );
}

function ChildStatusBadge({ status }) {
    const style = {
        present: 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300',
        late: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
        absent: 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
        excused: 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
        sick: 'bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-300',
    }[status] ?? 'bg-stone-100 text-stone-500 dark:bg-stone-700 dark:text-stone-400';

    return <span className={`rounded-full px-2.5 py-1 text-xs font-medium capitalize ${style}`}>{status ?? 'Not marked today'}</span>;
}

export default function Dashboard({ stats }) {
    const { auth } = usePage().props;
    const { t } = useTranslation();

    // A parent's home is its own page: one child at a time, and the five things a parent comes for.
    if (auth.role === 'parent') {
        return (
            <AuthenticatedLayout header={<h2 className="text-lg font-semibold leading-tight text-stone-800 dark:text-stone-200">{t('menu.home')}</h2>}>
                <Head title={t('menu.home')} />
                <div className="py-6 sm:py-8">
                    <div className="mx-auto max-w-2xl px-4 sm:px-6 lg:px-8">
                        <TodayHeader className="mb-4" />
                        <ParentHome stats={stats} />
                    </div>
                </div>
            </AuthenticatedLayout>
        );
    }

    // A teacher's home is its own page too, same reasoning as a parent's: near-universally phone-only,
    // first-time-computer users, for whom a 12-item office-admin sidebar is not something to ask them to
    // learn. Giant buttons for everything, their own classes shown plainly, nothing else.
    if (auth.role === 'teacher') {
        return (
            <AuthenticatedLayout header={<h2 className="text-lg font-semibold leading-tight text-stone-800 dark:text-stone-200">{t('menu.home')}</h2>}>
                <Head title={t('menu.home')} />
                <div className="py-6 sm:py-8">
                    <div className="mx-auto max-w-2xl px-4 sm:px-6 lg:px-8">
                        <TodayHeader className="mb-4" />
                        <TeacherHome stats={stats} />
                    </div>
                </div>
            </AuthenticatedLayout>
        );
    }

    return (
        <AuthenticatedLayout
            header={<h2 className="text-lg font-semibold leading-tight text-stone-800 dark:text-stone-200">{t('menu.home')}</h2>}
        >
            <Head title={t('menu.home')} />

            <div className="py-6 sm:py-8">
                <div className="mx-auto max-w-5xl space-y-10 px-4 sm:px-6 lg:px-8">
                    <TodayHeader />

                    <p className="text-lg text-stone-700 dark:text-stone-300">
                        {t('dashboard.welcome', { name: auth.user.name })}{auth.user.title ? ` — ${auth.user.title}` : ''}
                    </p>

                    {stats.demographics && (
                        <section aria-labelledby="demographics">
                            <h2 id="demographics" className="mb-4 text-xl font-semibold text-stone-900 dark:text-stone-100">{t('dashboard.student_statistics')}</h2>
                            <StudentDemographics demographics={stats.demographics} />
                        </section>
                    )}

                    <section aria-labelledby="glance">
                        <h2 id="glance" className="mb-4 text-xl font-semibold text-stone-900 dark:text-stone-100">{t('home.glance')}</h2>
                        {['owner', 'branch_admin', 'auditor'].includes(auth.role) && <OrgDashboard stats={stats} />}
                        {auth.role === 'accountant' && <AccountantDashboard stats={stats} />}
                    </section>

                    <HomeTasks stats={stats} />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
