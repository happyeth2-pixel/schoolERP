import {
    AcademicCapIcon,
    ArrowUturnLeftIcon,
    BanknotesIcon,
    BellIcon,
    BriefcaseIcon,
    BuildingOffice2Icon,
    ChatBubbleLeftRightIcon,
    CheckBadgeIcon,
    ClipboardDocumentCheckIcon,
    Cog6ToothIcon,
    CurrencyDollarIcon,
    DocumentChartBarIcon,
    DocumentTextIcon,
    IdentificationIcon,
    MagnifyingGlassIcon,
    MegaphoneIcon,
    PencilSquareIcon,
    PresentationChartBarIcon,
    ShieldCheckIcon,
    Squares2X2Icon,
    UserGroupIcon,
    UserPlusIcon,
    UsersIcon,
} from '@heroicons/react/24/outline';

// The home page answers "What do you want to do today?" with a handful of large task tiles.
//
// Authorization is NOT decided here. A tile is shown only if
//   1. its `navRoute` is one the person's own menu already offers (navigation.jsx builds that from their
//      permissions -- one source of truth), and
//   2. where the tile goes to a more specific page than the menu item does, they also hold `needs`
//      (e.g. "Register a student" needs manage-students; an Auditor's read-only Students menu item does not qualify).
// The server still enforces every route exactly as before; this only decides what to put in front of someone.
// Tiles use existing routes only -- nothing here is a second implementation of anything.

export const TASKS = {
    // ---- front desk / admin: people
    register_student: { icon: UserPlusIcon, route: 'students.create', navRoute: 'students.index', needs: 'manage-students' },
    find_student: { icon: MagnifyingGlassIcon, route: 'students.index', navRoute: 'students.index' },
    print_id: { icon: IdentificationIcon, route: 'students.index', navRoute: 'students.index', needs: 'manage-students', href: () => route('students.index', { task: 'card' }) },
    // "Record payment" starts from the student: find them, open Fees, record. Only for someone who holds verify-payments.
    record_payment: { icon: BanknotesIcon, route: 'students.index', navRoute: 'students.index', needs: 'verify-payments', href: () => route('students.index', { task: 'payment' }) },
    students: { icon: AcademicCapIcon, route: 'students.index', navRoute: 'students.index' },
    parents: { icon: UsersIcon, route: 'parents.index', navRoute: 'parents.index' },
    staff: { icon: BriefcaseIcon, route: 'staff.index', navRoute: 'staff.index' },
    classes: { icon: Squares2X2Icon, route: 'academic-structure.index', navRoute: 'academic-structure.index' },

    // ---- money
    check_payments: { icon: BanknotesIcon, route: 'payments.pending', navRoute: 'payments.pending', badge: (ctx) => ({ kind: 'waiting', count: ctx.stats?.pending_payments_count }) },
    invoices: { icon: DocumentTextIcon, route: 'invoices.index', navRoute: 'invoices.index' },
    refunds: { icon: ArrowUturnLeftIcon, route: 'invoices.index', navRoute: 'invoices.index', needs: 'refund-payments' },
    fees: { icon: CurrencyDollarIcon, route: 'fee-structures.index', navRoute: 'fee-structures.index' },

    // ---- teaching
    take_attendance: { icon: ClipboardDocumentCheckIcon, route: 'attendance.index', navRoute: 'attendance.index' },
    enter_marks: { icon: PencilSquareIcon, route: 'marks.index', navRoute: 'marks.index' },
    my_classes: { icon: Squares2X2Icon, href: () => '#my-classes', roles: ['teacher'] },
    attendance_summary: { icon: PresentationChartBarIcon, route: 'attendance-report.index', navRoute: 'attendance-report.index' },
    approve_results: { icon: CheckBadgeIcon, route: 'mark-batches.approvals', navRoute: 'mark-batches.approvals' },

    // ---- parents
    children: { icon: UserGroupIcon, href: () => '#children', roles: ['parent'] },
    attendance: { icon: ClipboardDocumentCheckIcon, route: 'family.attendance', navRoute: 'family.attendance' },
    parent_fees: {
        icon: CurrencyDollarIcon,
        roles: ['parent'],
        // one child with an invoice: straight to it; several: down to the children, each with its own invoice link
        href: (ctx) => {
            const invoices = (ctx.stats?.children ?? []).filter((c) => c.invoice_id);
            return invoices.length === 1 ? route('invoices.show', invoices[0].invoice_id) : '#children';
        },
    },
    report_card: { icon: DocumentChartBarIcon, route: 'family.report-cards', navRoute: 'family.report-cards' },

    // ---- everyone / more
    notifications: { icon: BellIcon, route: 'notifications.index', navRoute: 'notifications.index', badge: (ctx) => ({ kind: 'new', count: ctx.unread }) },
    announcements: { icon: MegaphoneIcon, route: 'announcements.index', navRoute: 'announcements.index' },
    complaints: { icon: ChatBubbleLeftRightIcon, route: 'complaints.index', navRoute: 'complaints.index' },
    branches: { icon: BuildingOffice2Icon, route: 'branches.index', navRoute: 'branches.index' },
    school_details: { icon: Cog6ToothIcon, route: 'school-settings.edit', navRoute: 'school-settings.edit' },
    activity_history: { icon: ShieldCheckIcon, route: 'audit-log.index', navRoute: 'audit-log.index' },
};

/**
 * Which set of everyday tasks fits this person. "Receptionist" is not a role in this system: it is a
 * branch admin whose permissions cover students but not staff or fees -- so it is recognised by what
 * they can do, not by a title.
 */
export function personaFor(role, has) {
    if (role === 'parent') return 'parent';
    if (role === 'teacher') return 'teacher';
    if (role === 'accountant') return 'accountant';
    if (role === 'auditor') return 'auditor';
    if (role === 'branch_admin' && has('manage-students') && !has('manage-staff') && !has('manage-fee-structures')) return 'frontdesk';
    return 'admin'; // owner and full branch admins
}

// Priority order per persona; the first six the person is authorised for become the big tiles, the rest
// (plus everything else they may use) sit under "More things you can do". Nothing authorised is ever dropped.
const PRIORITY = {
    parent: ['children', 'attendance', 'parent_fees', 'report_card', 'notifications', 'complaints', 'announcements'],
    // taking attendance and the teacher's own class are the first thing on their home page (TeacherHome), so they are not repeated as tiles
    teacher: ['enter_marks', 'notifications', 'announcements'],
    accountant: ['check_payments', 'invoices', 'refunds', 'fees', 'notifications', 'announcements'],
    frontdesk: ['register_student', 'find_student', 'record_payment', 'print_id', 'parents'],
    admin: ['students', 'staff', 'classes', 'fees', 'check_payments', 'attendance_summary'],
    auditor: ['students', 'attendance_summary', 'invoices', 'activity_history', 'complaints', 'classes'],
};

const MAX_TILES = 6;

/** ctx: { role, has(perm), navRoutes: Set, stats, unread } -> { main: [task], more: [task] }, each task = { key, ...TASKS[key], href } */
export function pickTasks(ctx) {
    const usable = (key) => {
        const task = TASKS[key];
        if (task.roles && !task.roles.includes(ctx.role)) return false;
        if (task.navRoute && !ctx.navRoutes.has(task.navRoute)) return false;
        if (task.needs && !ctx.has(task.needs)) return false;
        return true;
    };
    const resolve = (key) => ({ key, ...TASKS[key], href: TASKS[key].href ? TASKS[key].href(ctx) : route(TASKS[key].route) });

    const persona = personaFor(ctx.role, ctx.has);
    const preferred = PRIORITY[persona].filter(usable);
    const main = preferred.slice(0, MAX_TILES);
    const shown = new Set(main);

    // everything else this person may use, so nothing authorised is ever out of reach
    const more = Object.keys(TASKS)
        .filter((k) => !shown.has(k) && usable(k))
        // a tile that only re-labels a route already offered by another tile adds noise, not options
        .filter((k) => !main.some((m) => TASKS[m].route && TASKS[m].route === TASKS[k].route));

    return { persona, main: main.map(resolve), more: more.map(resolve) };
}
