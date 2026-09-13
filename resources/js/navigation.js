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
export function buildNavSections({ role, permissions = [] }) {
    const has = (perm) => permissions.includes(perm);
    const isAuditor = role === 'auditor';

    const sections = [
        {
            label: 'Overview',
            items: [
                { label: 'Dashboard', route: 'dashboard', enabled: true },
                { label: 'Notifications', route: 'notifications.index', enabled: true },
            ],
        },
    ];

    if (role === 'owner') {
        sections.push({
            label: 'Organization',
            items: [{ label: 'Branches', route: 'branches.index', enabled: true }],
        });
    }

    if (has('manage-school-settings') || has('manage-grading-scale')) {
        sections.push({
            label: 'Settings',
            items: [
                { label: 'School Profile', route: 'school-settings.edit', enabled: has('manage-school-settings'), show: has('manage-school-settings') },
                { label: 'Academic Years & Terms', route: 'academic-years.index', enabled: has('manage-school-settings'), show: has('manage-school-settings') },
                { label: 'Grading Scale', route: 'grade-bands.index', enabled: has('manage-grading-scale'), show: has('manage-grading-scale') },
            ].filter((i) => i.show !== false),
        });
    }

    if (has('manage-classes') || has('manage-teachers') || has('manage-grading-scale') || isAuditor) {
        sections.push({
            label: 'Academics',
            items: [
                { label: 'Grades', route: 'grades.index', enabled: has('manage-classes'), show: has('manage-classes') },
                { label: 'Sections', route: 'sections.index', enabled: has('manage-classes') || isAuditor, show: has('manage-classes') || isAuditor },
                { label: 'Subjects', route: 'subjects.index', enabled: has('manage-classes'), show: has('manage-classes') },
                { label: 'Teaching Assignments', route: 'teaching-assignments.index', enabled: has('manage-teachers'), show: has('manage-teachers') },
                { label: 'Exams', route: 'exams.index', enabled: has('manage-grading-scale'), show: has('manage-grading-scale') },
            ].filter((i) => i.show !== false),
        });
    }

    if (has('enter-marks') || has('approve-results') || has('publish-results')) {
        sections.push({
            label: 'Results',
            items: [
                { label: 'Enter Marks', route: 'marks.index', enabled: has('enter-marks'), show: has('enter-marks') },
                { label: 'Result Approvals', route: 'mark-batches.approvals', enabled: has('approve-results') || has('publish-results'), show: has('approve-results') || has('publish-results') },
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
            label: 'People',
            items: [
                { label: 'Staff Accounts', route: 'staff.index', enabled: has('manage-staff') || isAuditor, show: has('manage-staff') || isAuditor },
                { label: 'Students', route: 'students.index', enabled: has('manage-students') || isAuditor, show: has('manage-students') || isAuditor },
                { label: 'Parents', route: 'parents.index', enabled: has('manage-parents') || isAuditor, show: has('manage-parents') || isAuditor },
            ].filter((i) => i.show !== false),
        });
    }

    if (has('mark-attendance') || has('view-attendance-reports')) {
        sections.push({
            label: 'Attendance',
            items: [
                { label: 'Take Attendance', route: 'attendance.index', enabled: has('mark-attendance'), show: has('mark-attendance') },
                { label: 'Attendance Report', route: 'attendance-report.index', enabled: has('view-attendance-reports'), show: has('view-attendance-reports') },
            ].filter((i) => i.show !== false),
        });
    }

    if (has('manage-fee-structures') || has('manage-invoices') || has('verify-payments') || has('view-financial-reports')) {
        sections.push({
            label: 'Finance',
            items: [
                { label: 'Invoices', route: 'invoices.index', enabled: has('manage-invoices') || has('view-financial-reports'), show: has('manage-invoices') || has('view-financial-reports') },
                { label: 'Payment Verification', route: 'payments.pending', enabled: has('verify-payments'), show: has('verify-payments') },
                { label: 'Fee Structures', route: 'fee-structures.index', enabled: has('manage-fee-structures'), show: has('manage-fee-structures') },
            ].filter((i) => i.show !== false),
        });
    }

    if (has('view-audit-log')) {
        sections.push({
            label: 'Oversight',
            items: [{ label: 'Audit Log', route: 'audit-log.index', enabled: true }],
        });
    }

    if (role === 'parent' || role === 'student') {
        sections.push({
            label: 'My School',
            items: [
                { label: 'Report Card', enabled: false },
                { label: 'Attendance', enabled: false },
                { label: 'Announcements', enabled: false },
            ],
        });
    }

    return sections;
}
