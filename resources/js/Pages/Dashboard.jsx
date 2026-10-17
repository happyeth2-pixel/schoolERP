import AttendanceStatusBadge from '@/Components/AttendanceStatusBadge';
import Card3D from '@/Components/Card3D';
import HomeTasks from '@/Components/HomeTasks';
import TeacherHome from '@/Components/TeacherHome';
import ParentHome from '@/Components/ParentHome';
import TodayHeader from '@/Components/TodayHeader';
import GradientStatTile from '@/Components/GradientStatTile';
import Meter from '@/Components/Meter';
import SplitMeter from '@/Components/SplitMeter';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import {
    AcademicCapIcon,
    ArchiveBoxIcon,
    ComputerDesktopIcon,
    ArchiveBoxXMarkIcon,
    ArrowRightIcon,
    BanknotesIcon,
    BriefcaseIcon,
    CalendarIcon,
    ClipboardDocumentCheckIcon,
    ClockIcon,
    CurrencyDollarIcon,
    ExclamationTriangleIcon,
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

function OrgDashboard({ stats }) {
    const { t } = useTranslation();

    return (
        <div className="grid gap-4 sm:grid-cols-2">
            <GradientStatTile icon={UserGroupIcon} label={t('dashboard.total_students')} value={stats.total_students} color="blue" />
            <GradientStatTile icon={AcademicCapIcon} label={t('dashboard.total_teachers')} value={stats.total_teachers} color="purple" />
            <Card3D glow={glowFor(stats.today_attendance_rate, { good: 90, warning: 75 })} className="p-5">
                <Meter
                    bare
                    label={t('dashboard.today_attendance')}
                    value={stats.today_attendance_rate}
                    thresholds={{ good: 90, warning: 75 }}
                    sublabel={stats.today_marked > 0 ? `${stats.today_marked} students marked so far` : undefined}
                    emptyLabel={t('dashboard.not_marked_today')}
                />
            </Card3D>
            {stats.fees_total !== null && stats.fees_total !== undefined && (
            <Card3D glow={glowFor(stats.fee_collection_rate, { good: 75, warning: 40 })} className="p-5">
                <Meter
                    bare
                    label={t('dashboard.fee_collection_rate')}
                    value={stats.fee_collection_rate}
                    thresholds={{ good: 75, warning: 40 }}
                    emptyLabel="No invoices yet"
                />
            </Card3D>
            )}
            {stats.fees_total !== null && stats.fees_total !== undefined && (
            <Card3D glow="green" className="p-5">
                <SplitMeter bare label={t('dashboard.school_fees')} paid={stats.fees_paid} total={stats.fees_total} />
            </Card3D>
            )}
            {stats.pending_payments_count !== null && stats.pending_payments_count !== undefined && (
            <GradientStatTile icon={ClipboardDocumentCheckIcon} label={t('dashboard.payment_proofs_pending')} value={stats.pending_payments_count} color="amber" />
            )}
            {stats.inventory && (
                <>
                    <Link className="block" href={route('inventory-items.index')}><GradientStatTile icon={ArchiveBoxIcon} label={t('dashboard.inventory_total_items')} value={stats.inventory.total_items} color="blue" /></Link>
                    <Link className="block" href={route('inventory-items.index')}><GradientStatTile icon={ExclamationTriangleIcon} label={t('dashboard.inventory_low_stock')} value={stats.inventory.low_stock} color={stats.inventory.low_stock > 0 ? 'amber' : 'green'} /></Link>
                    <Link className="block" href={route('inventory-items.index')}><GradientStatTile icon={ArchiveBoxXMarkIcon} label={t('dashboard.inventory_out_of_stock')} value={stats.inventory.out_of_stock} color={stats.inventory.out_of_stock > 0 ? 'red' : 'green'} /></Link>
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
            {stats.procurement && (
                <>
                    {stats.procurement.pending_approvals !== null && (
                        <Link className="block" href={route('purchase-requests.index', { status: 'submitted' })}><GradientStatTile icon={ClipboardDocumentCheckIcon} label={t('dashboard.procurement_pending_approvals')} value={stats.procurement.pending_approvals} color={stats.procurement.pending_approvals > 0 ? 'amber' : 'green'} /></Link>
                    )}
                    {stats.procurement.my_pending > 0 && (
                        <Link className="block" href={route('purchase-requests.index')}><GradientStatTile icon={ClipboardDocumentCheckIcon} label={t('dashboard.my_pending_purchase_requests')} value={stats.procurement.my_pending} color="blue" /></Link>
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
            {stats.hr && (
                <>
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
                </>
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
            <Card3D glow="green" className="p-5">
                <SplitMeter bare label={t('dashboard.school_fees')} paid={stats.fees_paid} total={stats.fees_total} />
            </Card3D>
            <GradientStatTile icon={ClipboardDocumentCheckIcon} label={t('dashboard.payment_proofs_review')} value={stats.pending_payments_count} color="amber" />
            {stats.inventory && (
                <>
                    <Link className="block" href={route('inventory-items.index')}><GradientStatTile icon={ArchiveBoxIcon} label={t('dashboard.inventory_total_items')} value={stats.inventory.total_items} color="blue" /></Link>
                    <Link className="block" href={route('inventory-items.index')}><GradientStatTile icon={ExclamationTriangleIcon} label={t('dashboard.inventory_low_stock')} value={stats.inventory.low_stock} color={stats.inventory.low_stock > 0 ? 'amber' : 'green'} /></Link>
                </>
            )}
            {stats.requisitions && stats.requisitions.pending_approvals !== null && (
                <Link className="block" href={route('requisitions.index', { status: 'submitted' })}><GradientStatTile icon={ClipboardDocumentCheckIcon} label={t('dashboard.pending_approvals')} value={stats.requisitions.pending_approvals} color={stats.requisitions.pending_approvals > 0 ? 'amber' : 'green'} /></Link>
            )}
        </div>
    );
}

const GLOW_FOR_ATTENDANCE_STATUS = { complete: 'green', partial: 'amber', none: 'red', empty: 'gray' };

function HomeroomCard({ h }) {
    const pct = h.roster_count > 0 ? Math.round((h.marked_count / h.roster_count) * 100) : null;

    return (
        <Card3D glow={GLOW_FOR_ATTENDANCE_STATUS[h.attendance_status]} className="p-5">
            <div className="mb-3 flex items-start justify-between gap-3">
                <p className="text-base font-bold text-gray-900 dark:text-gray-100">{h.label}</p>
                <AttendanceStatusBadge status={h.attendance_status} />
            </div>
            {h.roster_count > 0 && (
                <Meter bare label="Marked today" value={pct} thresholds={{ good: 100, warning: 1 }} sublabel={`${h.marked_count} of ${h.roster_count} students`} />
            )}
            <Link href={route('attendance.show', h.id)} className="mt-4 inline-flex items-center gap-1.5 text-sm font-semibold text-indigo-600 hover:underline dark:text-indigo-400">
                {h.attendance_status === 'complete' ? 'Review attendance' : 'Take attendance'}
                <ArrowRightIcon className="h-4 w-4" />
            </Link>
        </Card3D>
    );
}

const SUBJECT_DOT_COLORS = ['bg-indigo-500', 'bg-violet-500', 'bg-sky-500', 'bg-emerald-500', 'bg-amber-500', 'bg-rose-500'];

function TeacherDashboard({ stats }) {
    const { t } = useTranslation();

    return (
        <div className="space-y-6" id="my-classes">
            <div>
                <h3 className="mb-2 text-sm font-semibold uppercase tracking-wide text-gray-400">{t('dashboard.my_homeroom')}</h3>
                {stats.homerooms.length === 0 && (
                    <p className="text-sm text-gray-400">You're not a class teacher for any section.</p>
                )}
                <div className="grid gap-4 sm:grid-cols-2">
                    {stats.homerooms.map((h) => (
                        <HomeroomCard key={h.id} h={h} />
                    ))}
                </div>
            </div>
            <div>
                <h3 className="mb-2 text-sm font-semibold uppercase tracking-wide text-gray-400">{t('dashboard.my_subjects')}</h3>
                {stats.teaching_assignments.length === 0 && (
                    <p className="text-sm text-gray-400">No subjects assigned yet.</p>
                )}
                <Card3D glow="indigo" className="divide-y divide-gray-100 dark:divide-gray-700">
                    {stats.teaching_assignments.map((a, i) => (
                        <div key={i} className="flex items-center gap-3 px-4 py-3 text-sm">
                            <span className={`h-2.5 w-2.5 shrink-0 rounded-full ${SUBJECT_DOT_COLORS[i % SUBJECT_DOT_COLORS.length]}`} />
                            <span className="font-medium text-gray-900 dark:text-gray-100">{a.subject}</span>
                            <span className="text-gray-400">— {a.section}</span>
                        </div>
                    ))}
                </Card3D>
            </div>
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
    }[status] ?? 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400';

    return <span className={`rounded-full px-2.5 py-1 text-xs font-medium capitalize ${style}`}>{status ?? 'Not marked today'}</span>;
}

export default function Dashboard({ stats }) {
    const { auth } = usePage().props;
    const { t } = useTranslation();

    // A parent's home is its own page: one child at a time, and the five things a parent comes for.
    if (auth.role === 'parent') {
        return (
            <AuthenticatedLayout header={<h2 className="text-lg font-semibold leading-tight text-gray-800 dark:text-gray-200">{t('menu.home')}</h2>}>
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

    return (
        <AuthenticatedLayout
            header={<h2 className="text-lg font-semibold leading-tight text-gray-800 dark:text-gray-200">{t('menu.home')}</h2>}
        >
            <Head title={t('menu.home')} />

            <div className="py-6 sm:py-8">
                <div className="mx-auto max-w-5xl space-y-10 px-4 sm:px-6 lg:px-8">
                    <TodayHeader />
                    <div>
                        {auth.role === 'teacher' ? (
                            <div className="mb-8"><TeacherHome stats={stats} /></div>
                        ) : (
                            <p className="mb-4 text-lg text-gray-700 dark:text-gray-300">
                                {t('dashboard.welcome', { name: auth.user.name })}{auth.user.title ? ` — ${auth.user.title}` : ''}
                            </p>
                        )}
                        <HomeTasks stats={stats} />
                    </div>

                    <section aria-labelledby="glance">
                        <h2 id="glance" className="mb-4 text-xl font-semibold text-gray-900 dark:text-gray-100">{t('home.glance')}</h2>
                        {['owner', 'branch_admin', 'auditor'].includes(auth.role) && <OrgDashboard stats={stats} />}
                        {auth.role === 'teacher' && <TeacherDashboard stats={stats} />}
                        {auth.role === 'accountant' && <AccountantDashboard stats={stats} />}
                    </section>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
