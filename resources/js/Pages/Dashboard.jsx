import Meter from '@/Components/Meter';
import SplitMeter from '@/Components/SplitMeter';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, usePage } from '@inertiajs/react';

function StatTile({ label, value, hint }) {
    return (
        <div className="rounded-lg border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-800">
            <p className="text-sm text-gray-500 dark:text-gray-400">{label}</p>
            <p className="mt-1 text-2xl font-semibold text-gray-900 dark:text-gray-100">{value}</p>
            {hint && <p className="mt-1 text-xs text-gray-400">{hint}</p>}
        </div>
    );
}

function PlaceholderTile({ label }) {
    return (
        <div className="rounded-lg border border-dashed border-gray-300 bg-white p-5 dark:border-gray-600 dark:bg-gray-800">
            <p className="text-sm text-gray-500 dark:text-gray-400">{label}</p>
            <p className="mt-1 text-2xl font-semibold text-gray-300 dark:text-gray-600">—</p>
            <p className="mt-1 text-xs text-gray-400">Arrives with its module in a later phase</p>
        </div>
    );
}

function OrgDashboard({ title, blurb, stats }) {
    return (
        <div className="grid gap-4 sm:grid-cols-2">
            <StatTile label="Total Students" value={stats.total_students} />
            <StatTile label="Total Teachers" value={stats.total_teachers} />
            <Meter
                label="Today's Attendance"
                value={stats.today_attendance_rate}
                thresholds={{ good: 90, warning: 75 }}
                sublabel={stats.today_marked > 0 ? `${stats.today_marked} students marked so far` : undefined}
                emptyLabel="Not marked yet"
            />
            <Meter
                label="Fee Collection Rate"
                value={stats.fee_collection_rate}
                thresholds={{ good: 75, warning: 40 }}
                emptyLabel="No invoices yet"
            />
            <SplitMeter label="School Fees (Current Year)" paid={stats.fees_paid} total={stats.fees_total} />
            <StatTile label="Payment Proofs Pending" value={stats.pending_payments_count} />
        </div>
    );
}

function AccountantDashboard({ stats }) {
    return (
        <div className="grid gap-4 sm:grid-cols-2">
            <StatTile label="Fees Collected Today (ETB)" value={Number(stats.collected_today).toLocaleString()} />
            <StatTile label="Fees Collected This Month (ETB)" value={Number(stats.collected_this_month).toLocaleString()} />
            <SplitMeter label="School Fees (Current Year)" paid={stats.fees_paid} total={stats.fees_total} />
            <StatTile label="Payment Proofs Pending Review" value={stats.pending_payments_count} />
        </div>
    );
}

function TeacherDashboard({ stats }) {
    return (
        <div className="space-y-6">
            <div>
                <h3 className="mb-2 text-sm font-semibold uppercase tracking-wide text-gray-400">My Homeroom</h3>
                {stats.homerooms.length === 0 && (
                    <p className="text-sm text-gray-400">You're not a class teacher for any section.</p>
                )}
                <div className="space-y-2">
                    {stats.homerooms.map((h) => (
                        <div key={h.id} className="flex items-center justify-between rounded-lg bg-white p-4 shadow-sm dark:bg-gray-800">
                            <span className="text-sm font-medium text-gray-900 dark:text-gray-100">{h.label}</span>
                            {h.attendance_taken_today ? (
                                <span className="text-xs text-green-600 dark:text-green-400">Attendance taken today</span>
                            ) : (
                                <Link href={route('attendance.show', h.id)} className="text-xs text-indigo-600 hover:underline dark:text-indigo-400">
                                    Take attendance
                                </Link>
                            )}
                        </div>
                    ))}
                </div>
            </div>
            <div>
                <h3 className="mb-2 text-sm font-semibold uppercase tracking-wide text-gray-400">My Subjects</h3>
                {stats.teaching_assignments.length === 0 && (
                    <p className="text-sm text-gray-400">No subjects assigned yet.</p>
                )}
                <ul className="space-y-1">
                    {stats.teaching_assignments.map((a, i) => (
                        <li key={i} className="rounded-lg bg-white px-4 py-2 text-sm shadow-sm dark:bg-gray-800">
                            {a.subject} — <span className="text-gray-500">{a.section}</span>
                        </li>
                    ))}
                </ul>
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
                <PlaceholderTile label="Pending Marks Entry" />
                <PlaceholderTile label="Assignments to Grade" />
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

function ParentDashboard({ stats }) {
    return (
        <div className="space-y-4">
            {stats.children.map((c) => (
                <div key={c.id} className="rounded-lg bg-white p-5 shadow-sm dark:bg-gray-800">
                    <div className="mb-3 flex items-center justify-between">
                        <p className="text-base font-semibold text-gray-900 dark:text-gray-100">{c.name}</p>
                        <ChildStatusBadge status={c.today_status} />
                    </div>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Meter label="Attendance (this year)" value={c.attendance_rate} thresholds={{ good: 90, warning: 75 }} />
                        <SplitMeter label="School Fees" paid={c.invoice_paid} total={c.invoice_total} />
                    </div>
                    <div className="mt-3 flex items-center justify-between">
                        <Link href={route('report-card.show', c.id)} className="text-sm text-indigo-600 hover:underline dark:text-indigo-400">
                            View Report Card
                        </Link>
                        {c.invoice_id && (
                            <Link href={route('invoices.show', c.invoice_id)} className="text-sm text-indigo-600 hover:underline dark:text-indigo-400">
                                View Invoice
                            </Link>
                        )}
                    </div>
                </div>
            ))}
            {stats.children.length === 0 && <p className="text-sm text-gray-400">No children linked to your account yet.</p>}
        </div>
    );
}

function StudentDashboard({ stats }) {
    return (
        <div className="space-y-4">
            <div className="grid gap-4 sm:grid-cols-2">
                <Meter label="My Attendance (this year)" value={stats.attendance_rate} thresholds={{ good: 90, warning: 75 }} />
                <div className="rounded-lg border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-800">
                    <p className="text-sm text-gray-500 dark:text-gray-400">Today</p>
                    <div className="mt-2"><ChildStatusBadge status={stats.today_status} /></div>
                </div>
            </div>
            {stats.student_id ? (
                <Link href={route('report-card.show', stats.student_id)} className="text-sm text-indigo-600 hover:underline dark:text-indigo-400">
                    View Report Card
                </Link>
            ) : (
                <p className="text-sm text-gray-400">Your account isn't linked to a student record yet — ask the school office to link it.</p>
            )}
            <div className="grid gap-4 sm:grid-cols-2">
                <PlaceholderTile label="Assignments Due" />
            </div>
        </div>
    );
}

const ROLE_COPY = {
    owner: { title: 'Owner Dashboard', blurb: 'Organization-wide view across every branch.' },
    branch_admin: { title: 'Branch Admin Dashboard', blurb: 'Scoped to your branch. Menu items reflect your specific permissions.' },
    auditor: { title: 'Auditor Dashboard', blurb: 'Read-only across every module. No edit access anywhere.' },
    teacher: { title: 'Teacher Dashboard', blurb: "Your homeroom and subjects." },
    accountant: { title: 'Accountant Dashboard', blurb: 'Finance only — no access to grades or attendance.' },
    parent: { title: 'Parent Dashboard', blurb: 'Your children, their academics, and fees.' },
    student: { title: 'Student Dashboard', blurb: 'Your own record.' },
};

export default function Dashboard({ stats }) {
    const { auth } = usePage().props;
    const copy = ROLE_COPY[auth.role] ?? { title: 'Dashboard', blurb: '' };

    return (
        <AuthenticatedLayout
            header={<h2 className="text-lg font-semibold leading-tight text-gray-800 dark:text-gray-200">{copy.title}</h2>}
        >
            <Head title="Dashboard" />

            <div className="py-8">
                <div className="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">
                    <div className="overflow-hidden rounded-lg bg-white shadow-sm dark:bg-gray-800">
                        <div className="p-6">
                            <p className="text-sm text-gray-500 dark:text-gray-400">{copy.blurb}</p>
                            <p className="mt-2 text-lg font-medium text-gray-900 dark:text-gray-100">
                                Welcome, {auth.user.name}{auth.user.title ? ` — ${auth.user.title}` : ''}
                            </p>
                        </div>
                    </div>

                    {['owner', 'branch_admin', 'auditor'].includes(auth.role) && <OrgDashboard stats={stats} />}
                    {auth.role === 'teacher' && <TeacherDashboard stats={stats} />}
                    {auth.role === 'parent' && <ParentDashboard stats={stats} />}
                    {auth.role === 'student' && <StudentDashboard stats={stats} />}
                    {auth.role === 'accountant' && <AccountantDashboard stats={stats} />}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
