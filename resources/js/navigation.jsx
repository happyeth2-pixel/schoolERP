import {
    AcademicCapIcon,
    ArrowUpCircleIcon,
    BanknotesIcon,
    BellIcon,
    BookOpenIcon,
    BriefcaseIcon,
    BuildingOffice2Icon,
    CalculatorIcon,
    CalendarDaysIcon,
    CalendarIcon,
    ChartBarIcon,
    ChatBubbleLeftRightIcon,
    CheckBadgeIcon,
    ClipboardDocumentCheckIcon,
    ClipboardDocumentListIcon,
    Cog6ToothIcon,
    CurrencyDollarIcon,
    DocumentChartBarIcon,
    DocumentTextIcon,
    ExclamationTriangleIcon,
    HomeIcon,
    MegaphoneIcon,
    PencilIcon,
    PresentationChartBarIcon,
    QuestionMarkCircleIcon,
    ArchiveBoxIcon,
    ComputerDesktopIcon,
    ShieldCheckIcon,
    Squares2X2Icon,
    TruckIcon,
    UserGroupIcon,
    ScaleIcon,
    ClockIcon,
    IdentificationIcon,
    StarIcon,
} from '@heroicons/react/24/outline';

/**
 * Sidebar navigation, gated by permission rather than by role name.
 * This is what makes the "same tier, different permissions" model real
 * in the UI: two branch_admin accounts (e.g. a Vice Principal and a
 * Registrar) see different menus purely because their individual
 * permission sets differ — nothing here checks a role directly except
 * to show Owner their org-wide items.
 *
 * Items without a `route` aren't built yet — they render disabled with
 * a "coming soon" hint so the structure is visible without dead links.
 */
export function buildNavSections({ role, permissions = [], t = (key, fallback) => fallback }) {
    const has = (perm) => permissions.includes(perm);
    const isAuditor = role === 'auditor';
    const n = (key, fallback) => t(`nav.${key}`, fallback);
    const s = (key, fallback) => t(`section.${key}`, fallback);
    // Plain-school-language labels (menu.*). Only labels that were renamed live here; the rest keep
    // their original nav.* strings so existing (trusted) translations stay in use.
    const m = (key, fallback) => t(`menu.${key}`, fallback);

    const sections = [
        {
            id: 'overview',
            label: s('overview', 'Overview'),
            items: [
                { label: m('home', 'Home'), route: 'dashboard', enabled: true, icon: HomeIcon },
                { label: n('notifications', 'Notifications'), route: 'notifications.index', enabled: true, icon: BellIcon },
                { label: n('help', 'Help'), route: 'help.index', enabled: true, icon: QuestionMarkCircleIcon },
                { label: n('my_leave', 'My Leave'), route: 'leave-requests.mine', enabled: has('leave-request') || has('leave-approve'), show: has('leave-request') || has('leave-approve'), icon: CalendarIcon },
                { label: n('my_performance', 'My Performance'), route: 'performance-reviews.mine', enabled: true, show: !['parent', 'student'].includes(role), icon: StarIcon },
                { label: n('my_payslips', 'My Payslips'), route: 'payslips.mine', enabled: true, show: !['parent', 'student'].includes(role), icon: BanknotesIcon },
                { label: n('my_timetable', 'My Timetable'), route: 'timetable.mine', enabled: true, show: role === 'teacher', icon: CalendarDaysIcon },
                { label: n('my_library_loans', 'My Library Loans'), route: 'library.mine', enabled: true, show: !['parent', 'student'].includes(role), icon: BookOpenIcon },
            ].filter((i) => i.show !== false),
        },
    ];

    if (role === 'owner') {
        sections.push({
            id: 'organization',
            label: s('organization', 'Organization'),
            items: [{ label: n('branches', 'Branches'), route: 'branches.index', enabled: true, icon: BuildingOffice2Icon }],
        });
    }

    if (has('manage-school-settings') || has('manage-grading-scale')) {
        sections.push({
            id: 'settings',
            label: s('settings', 'Settings'),
            items: [
                { label: m('school_details', 'School Details'), route: 'school-settings.edit', enabled: has('manage-school-settings'), show: has('manage-school-settings'), icon: Cog6ToothIcon },
                { label: m('school_year', 'School Year & Terms'), route: 'academic-years.index', enabled: has('manage-school-settings'), show: has('manage-school-settings'), icon: CalendarIcon },
                { label: n('grading_scale', 'Grading Scale'), route: 'grade-bands.index', enabled: has('manage-grading-scale'), show: has('manage-grading-scale'), icon: ChartBarIcon },
            ].filter((i) => i.show !== false),
        });
    }

    if (has('manage-classes') || has('manage-teachers') || has('manage-grading-scale') || has('timetable-view') || has('timetable-manage') || isAuditor) {
        const seesTimetable = has('timetable-view') || has('timetable-manage') || isAuditor;
        sections.push({
            id: 'academics',
            label: m('section_classes', 'Classes & Teaching'),
            items: [
                { label: m('classes', 'Classes'), route: 'academic-structure.index', enabled: has('manage-classes') || isAuditor, show: has('manage-classes') || isAuditor, icon: Squares2X2Icon },
                { label: n('subjects', 'Subjects'), route: 'subjects.index', enabled: has('manage-classes'), show: has('manage-classes'), icon: DocumentTextIcon },
                { label: m('who_teaches_what', 'Who Teaches What'), route: 'teaching-assignments.index', enabled: has('manage-teachers'), show: has('manage-teachers'), icon: ClipboardDocumentListIcon },
                { label: n('exams', 'Exams'), route: 'exams.index', enabled: has('manage-grading-scale'), show: has('manage-grading-scale'), icon: PresentationChartBarIcon },
                { label: n('timetable', 'Timetable'), route: 'timetable.index', enabled: seesTimetable, show: seesTimetable, icon: CalendarDaysIcon },
                { label: n('periods', 'Periods'), route: 'periods.index', enabled: seesTimetable, show: seesTimetable, icon: ClockIcon },
                { label: n('rooms', 'Rooms'), route: 'rooms.index', enabled: seesTimetable, show: seesTimetable, icon: BuildingOffice2Icon },
            ].filter((i) => i.show !== false),
        });
    }

    if (has('enter-marks') || has('approve-results') || has('publish-results')) {
        sections.push({
            id: 'results',
            label: s('results', 'Results'),
            items: [
                { label: n('enter_marks', 'Enter Marks'), route: 'marks.index', enabled: has('enter-marks'), show: has('enter-marks'), icon: PencilIcon },
                { label: m('approve_results', 'Approve Results'), route: 'mark-batches.approvals', enabled: has('approve-results') || has('publish-results'), show: has('approve-results') || has('publish-results'), icon: CheckBadgeIcon },
            ].filter((i) => i.show !== false),
        });
    }

    if (
        has('manage-staff') ||
        has('manage-students') ||
        has('manage-parents') ||
        isAuditor
    ) {
        sections.push({
            id: 'people',
            label: s('people', 'People'),
            items: [
                { label: m('staff', 'Staff'), route: 'staff.index', enabled: has('manage-staff') || isAuditor, show: has('manage-staff') || isAuditor, icon: BriefcaseIcon },
                { label: n('students', 'Students'), route: 'students.index', enabled: has('manage-students') || isAuditor, show: has('manage-students') || isAuditor, icon: AcademicCapIcon },
                { label: m('parents_guardians', 'Parents & Guardians'), route: 'parents.index', enabled: has('manage-parents') || isAuditor, show: has('manage-parents') || isAuditor, icon: UserGroupIcon },
                { label: m('move_students_up', 'Move Students Up a Grade'), route: 'promotions.index', enabled: has('manage-students'), show: has('manage-students'), icon: ArrowUpCircleIcon },
            ].filter((i) => i.show !== false),
        });
    }

    if (has('discipline-view') || has('discipline-manage') || isAuditor) {
        sections.push({
            id: 'discipline',
            label: s('discipline', 'Discipline'),
            items: [
                { label: n('discipline_dashboard', 'Discipline'), route: 'discipline.index', enabled: true, icon: ExclamationTriangleIcon },
            ],
        });
    }

    if (has('manage-assignments')) {
        sections.push({
            id: 'assignments',
            label: s('assignments', 'Assignments'),
            items: [
                { label: n('assignments', 'Assignments'), route: 'assignments.index', enabled: true, icon: BookOpenIcon },
            ],
        });
    }

    if (has('mark-attendance') || has('view-attendance-reports')) {
        sections.push({
            id: 'attendance',
            label: s('attendance', 'Attendance'),
            items: [
                { label: n('take_attendance', 'Take Attendance'), route: 'attendance.index', enabled: has('mark-attendance'), show: has('mark-attendance'), icon: ClipboardDocumentCheckIcon },
                { label: n('period_attendance', 'Period Attendance'), route: 'period-attendance.index', enabled: has('mark-attendance'), show: has('mark-attendance'), icon: ClockIcon },
                { label: n('attendance_report', 'Attendance Report'), route: 'attendance-report.index', enabled: has('view-attendance-reports'), show: has('view-attendance-reports'), icon: PresentationChartBarIcon },
            ].filter((i) => i.show !== false),
        });
    }

    if (has('manage-fee-structures') || has('manage-invoices') || has('verify-payments') || has('view-financial-reports')) {
        sections.push({
            id: 'finance',
            label: m('section_money', 'Money'),
            items: [
                { label: n('invoices', 'Invoices'), route: 'invoices.index', enabled: has('manage-invoices') || has('view-financial-reports'), show: has('manage-invoices') || has('view-financial-reports'), icon: DocumentTextIcon },
                { label: m('check_payments', 'Check Payments'), route: 'payments.pending', enabled: has('verify-payments'), show: has('verify-payments'), icon: BanknotesIcon },
                { label: m('school_fees', 'School Fees'), route: 'fee-structures.index', enabled: has('manage-fee-structures'), show: has('manage-fee-structures'), icon: CurrencyDollarIcon },
                { label: m('extra_services', 'Extra Services'), route: 'optional-services.index', enabled: has('manage-fee-structures'), show: has('manage-fee-structures'), icon: TruckIcon },
            ].filter((i) => i.show !== false),
        });
    }

    if (role !== 'parent') {
        sections.push({
            id: 'communication',
            label: s('communication', 'Communication'),
            items: [
                { label: n('announcements', 'Announcements'), route: 'announcements.index', enabled: true, icon: MegaphoneIcon },
                { label: n('calendar', 'Calendar'), route: 'calendar.index', enabled: true, icon: CalendarDaysIcon },
                { label: n('complaints', 'Complaints'), route: 'complaints.index', enabled: true, show: has('manage-complaints') || isAuditor, icon: ChatBubbleLeftRightIcon },
            ].filter((i) => i.show !== false),
        });
    }

    if (has('inventory-view') || has('inventory-manage') || has('stock-receive') || has('stock-issue') || has('stock-transfer') || has('stock-adjust') || has('requisition-create') || has('requisition-approve') || has('requisition-issue') || has('asset-view') || has('asset-manage') || isAuditor) {
        const seesRequisitions = has('requisition-create') || has('requisition-approve') || has('requisition-issue');
        const seesAssets = has('asset-view') || has('asset-manage') || isAuditor;
        const seesStocktakes = has('stock-adjust') || isAuditor;
        sections.push({
            id: 'inventory',
            label: s('inventory', 'Inventory'),
            items: [
                { label: n('inventory_items', 'Items & Stock'), route: 'inventory-items.index', enabled: true, icon: ArchiveBoxIcon },
                { label: n('inventory_stores', 'Stores'), route: 'inventory-stores.index', enabled: true, icon: BuildingOffice2Icon },
                { label: n('inventory_movements', 'Stock Movements'), route: 'stock-movements.index', enabled: true, icon: ClipboardDocumentListIcon },
                { label: n('requisitions', 'Requisitions'), route: 'requisitions.index', enabled: seesRequisitions, show: seesRequisitions, icon: ClipboardDocumentCheckIcon },
                { label: n('assets', 'Assets'), route: 'assets.index', enabled: seesAssets, show: seesAssets, icon: ComputerDesktopIcon },
                { label: n('stocktakes', 'Stocktakes'), route: 'stocktakes.index', enabled: seesStocktakes, show: seesStocktakes, icon: ClipboardDocumentListIcon },
            ].filter((i) => i.show !== false),
        });
    }

    if (has('library-view') || has('library-manage') || isAuditor) {
        sections.push({
            id: 'library',
            label: s('library', 'Library'),
            items: [
                { label: n('library_loans', 'Loans'), route: 'library.index', enabled: true, icon: ClipboardDocumentCheckIcon },
                { label: n('library_catalog', 'Catalog'), route: 'library-books.index', enabled: true, icon: BookOpenIcon },
                { label: n('library_settings', 'Library Settings'), route: 'library-settings.edit', enabled: true, icon: Cog6ToothIcon },
            ],
        });
    }

    if (has('supplier-manage') || has('purchase-request-create') || has('purchase-approve') || has('purchase-approve-large') || has('quotation-manage') || has('purchase-order-manage') || has('supplier-bill-manage') || has('supplier-payment-manage') || isAuditor) {
        const seesPurchaseRequests = has('purchase-request-create') || has('purchase-approve') || has('purchase-approve-large');
        const seesSuppliers = has('supplier-manage') || isAuditor;
        const seesPurchaseOrders = has('purchase-order-manage') || isAuditor;
        const seesSupplierBills = has('supplier-bill-manage') || has('supplier-payment-manage') || isAuditor;
        sections.push({
            id: 'procurement',
            label: s('procurement', 'Procurement'),
            items: [
                { label: n('purchase_requests', 'Purchase Requests'), route: 'purchase-requests.index', enabled: seesPurchaseRequests, show: seesPurchaseRequests, icon: ClipboardDocumentCheckIcon },
                { label: n('purchase_orders', 'Purchase Orders'), route: 'purchase-orders.index', enabled: seesPurchaseOrders, show: seesPurchaseOrders, icon: ClipboardDocumentListIcon },
                { label: n('supplier_bills', 'Supplier Bills'), route: 'supplier-bills.index', enabled: seesSupplierBills, show: seesSupplierBills, icon: DocumentTextIcon },
                { label: n('suppliers', 'Suppliers'), route: 'suppliers.index', enabled: seesSuppliers, show: seesSuppliers, icon: BuildingOffice2Icon },
            ].filter((i) => i.show !== false),
        });
    }

    if (has('accounting-view') || has('accounting-manage') || has('accounting-period-manage') || has('accounting-period-reopen') || isAuditor) {
        const seesAccounting = has('accounting-view') || has('accounting-manage') || isAuditor;
        const seesPeriods = has('accounting-period-manage') || has('accounting-period-reopen') || seesAccounting;
        sections.push({
            id: 'accounting',
            label: s('accounting', 'Accounting'),
            items: [
                { label: n('chart_of_accounts', 'Chart of Accounts'), route: 'accounts.index', enabled: seesAccounting, show: seesAccounting, icon: CalculatorIcon },
                { label: n('journal_entries', 'Journal Entries'), route: 'journal-entries.index', enabled: seesAccounting, show: seesAccounting, icon: ClipboardDocumentListIcon },
                { label: n('journals', 'Journals'), route: 'journals.index', enabled: seesAccounting, show: seesAccounting, icon: DocumentTextIcon },
                { label: n('accounting_periods', 'Accounting Periods'), route: 'accounting-periods.index', enabled: seesPeriods, show: seesPeriods, icon: CalendarIcon },
                { label: n('general_ledger', 'General Ledger'), route: 'reports.ledger', enabled: seesAccounting, show: seesAccounting, icon: ChartBarIcon },
                { label: n('trial_balance', 'Trial Balance'), route: 'reports.trial-balance', enabled: seesAccounting, show: seesAccounting, icon: PresentationChartBarIcon },
                { label: n('income_statement', 'Income Statement'), route: 'reports.income-statement', enabled: seesAccounting, show: seesAccounting, icon: DocumentChartBarIcon },
                { label: n('balance_sheet', 'Balance Sheet'), route: 'reports.balance-sheet', enabled: seesAccounting, show: seesAccounting, icon: ScaleIcon },
                { label: n('cash_summary', 'Cash & Bank Summary'), route: 'reports.cash-summary', enabled: seesAccounting, show: seesAccounting, icon: BanknotesIcon },
                { label: n('ar_aging', 'Who Still Owes'), route: 'reports.ar-aging', enabled: seesAccounting, show: seesAccounting, icon: ClockIcon },
                { label: n('ap_aging', 'What We Still Owe'), route: 'reports.ap-aging', enabled: seesAccounting, show: seesAccounting, icon: ClockIcon },
                { label: n('reconciliation', 'Reconciliation Check'), route: 'reports.reconciliation', enabled: seesAccounting, show: seesAccounting, icon: ClipboardDocumentCheckIcon },
            ].filter((i) => i.show !== false),
        });
    }

    if (has('employee-view') || has('employee-manage') || has('staff-attendance-view') || has('staff-attendance-manage') || has('leave-view') || has('leave-approve') || has('performance-view') || has('performance-manage') || has('payroll-view') || has('payroll-manage') || isAuditor) {
        const seesHr = has('employee-view') || has('employee-manage') || isAuditor;
        const seesStaffAttendance = has('staff-attendance-view') || has('staff-attendance-manage') || isAuditor;
        const seesLeaveAdmin = has('leave-view') || has('leave-approve') || isAuditor;
        const seesPerformanceAdmin = has('performance-view') || has('performance-manage') || isAuditor;
        const seesPayrollAdmin = has('payroll-view') || has('payroll-manage') || isAuditor;
        sections.push({
            id: 'hr',
            label: s('hr', 'Human Resources'),
            items: [
                { label: n('employees', 'Employees'), route: 'employees.index', enabled: seesHr, show: seesHr, icon: UserGroupIcon },
                { label: n('departments', 'Departments'), route: 'departments.index', enabled: seesHr, show: seesHr, icon: BuildingOffice2Icon },
                { label: n('job_titles', 'Job Titles'), route: 'positions.index', enabled: seesHr, show: seesHr, icon: IdentificationIcon },
                { label: n('staff_attendance', 'Staff Attendance'), route: 'staff-attendance.index', enabled: seesStaffAttendance, show: seesStaffAttendance, icon: ClipboardDocumentCheckIcon },
                { label: n('attendance_corrections', 'Attendance Corrections'), route: 'staff-attendance.corrections', enabled: seesStaffAttendance, show: seesStaffAttendance, icon: ClipboardDocumentCheckIcon },
                { label: n('leave_types', 'Leave Types'), route: 'leave-types.index', enabled: seesLeaveAdmin, show: seesLeaveAdmin, icon: CalendarIcon },
                { label: n('pending_leave', 'Pending Leave Requests'), route: 'leave-requests.pending', enabled: seesLeaveAdmin, show: seesLeaveAdmin, icon: CalendarIcon },
                { label: n('performance_cycles', 'Review Cycles'), route: 'performance-cycles.index', enabled: seesPerformanceAdmin, show: seesPerformanceAdmin, icon: StarIcon },
                { label: n('payroll_runs', 'Payroll Runs'), route: 'payroll-runs.index', enabled: seesPayrollAdmin, show: seesPayrollAdmin, icon: BanknotesIcon },
                { label: n('payroll_settings', 'Payroll Settings'), route: 'payroll-settings.edit', enabled: seesPayrollAdmin, show: seesPayrollAdmin, icon: BanknotesIcon },
            ].filter((i) => i.show !== false),
        });
    }

    const seesAnyReport = has('view-attendance-reports') || has('manage-invoices') || has('view-financial-reports')
        || has('accounting-view') || has('accounting-manage') || has('manage-students') || has('manage-staff')
        || has('view-audit-log') || isAuditor;
    if (seesAnyReport) {
        sections.push({
            id: 'reports',
            label: m('section_reports', 'Reports'),
            items: [{ label: n('reports', 'Reports'), route: 'reports.index', enabled: true, icon: DocumentChartBarIcon }],
        });
    }

    if (has('view-audit-log')) {
        sections.push({
            id: 'oversight',
            label: m('section_history', 'History'),
            items: [{ label: m('activity_history', 'Activity History'), route: 'audit-log.index', enabled: true, icon: ShieldCheckIcon }],
        });
    }

    if (role === 'parent') {
        sections.push({
            id: 'my_school',
            label: s('my_school', 'My School'),
            items: [
                { label: n('results', 'Results'), route: 'family.results', enabled: true, icon: AcademicCapIcon },
                { label: n('report_card', 'Report Card'), route: 'family.report-cards', enabled: true, icon: DocumentChartBarIcon },
                { label: n('attendance', 'Attendance'), route: 'family.attendance', enabled: true, icon: ClipboardDocumentCheckIcon },
                { label: n('homework', 'Homework'), route: 'family.homework', enabled: true, icon: BookOpenIcon },
                { label: n('family_timetable', 'Timetable'), route: 'family.timetable', enabled: true, icon: CalendarDaysIcon },
                { label: n('announcements', 'Announcements'), route: 'announcements.index', enabled: true, icon: MegaphoneIcon },
                { label: n('calendar', 'Calendar'), route: 'calendar.index', enabled: true, icon: CalendarDaysIcon },
                { label: n('complaints', 'Complaints'), route: 'complaints.index', enabled: true, icon: ChatBubbleLeftRightIcon },
            ],
        });
    }

    return sections;
}

/** Every route the person's menu shows as usable. The home-page tiles use this so they can never offer
 *  something the menu (and therefore the person's permissions) does not. */
export function enabledRoutes(sections) {
    return new Set(sections.flatMap((section) => section.items.filter((i) => i.enabled && i.route).map((i) => i.route)));
}
