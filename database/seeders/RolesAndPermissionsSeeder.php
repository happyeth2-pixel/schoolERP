<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * The 7 real actor tiers. Job titles (Vice Principal, Registrar,
     * Librarian, etc.) are all "branch_admin" accounts with a display
     * `title` and their own individually toggled permissions — not
     * separate roles.
     */
    public const ROLES = [
        'owner',
        'branch_admin',
        'teacher',
        'accountant',
        'parent',
        'student',
        'auditor',
    ];

    /**
     * Every permission that exists in the system. Grouped by module so
     * it's easy to see what each tier gets by default and what a
     * Branch Admin's title-specific toggles can turn on/off.
     */
    public const PERMISSIONS = [
        // Organization / school setup
        'manage-branches',
        'manage-school-settings',
        'manage-grading-scale',
        'manage-staff',

        // People
        'manage-students',
        'manage-parents',
        'manage-teachers',
        'manage-classes',
        'override-section-capacity', // enrol/promote into a full section, with a recorded reason

        // Student medical records (deliberately separate from manage-students -- holding the broad
        // student-admin permission alone must never imply seeing health data)
        'medical-records-view',
        'medical-records-manage',

        // Daily academics
        'mark-attendance',
        'view-attendance-reports',
        'enter-marks',
        'approve-results',
        'publish-results',
        'manage-assignments',

        // Finance
        'manage-fee-structures',
        'manage-invoices',
        'verify-payments',
        'view-financial-reports',
        'refund-payments',           // reverse or refund a verified payment
        'approve-large-discounts',   // discounts/waivers above the school's limits

        // Communication
        'manage-announcements',
        'manage-complaints',
        'manage-calendar',

        // Oversight
        'view-audit-log',

        // Inventory & stores (Phase A: catalog + stock ledger. Phase B: requisitions/approvals/issuing. Assets follow later.)
        'inventory-view',
        'inventory-manage',
        'stock-receive',
        'stock-issue',
        'stock-transfer',
        'stock-adjust',
        'requisition-create',
        'requisition-approve',
        'requisition-issue',
        'asset-view',
        'asset-manage',

        // Procurement (Phase 1: suppliers, purchase requests, approval)
        'supplier-manage',
        'purchase-request-create',
        'purchase-approve',
        'purchase-approve-large',
        'quotation-manage',
        'purchase-order-manage',
        'goods-receipt-manage',
        'supplier-bill-manage',
        'supplier-payment-manage',

        // Accounting (double-entry engine: chart of accounts, journals, periods -- separate from `money`,
        // which governs the existing fee/invoice/payment screens ordinary staff already use)
        'accounting-view',
        'accounting-manage',
        'accounting-period-manage',
        'accounting-period-reopen',

        // Human Resources (Phase A: employee profiles, departments, positions -- separate from
        // `manage-staff`, which governs login accounts/roles/permissions, not HR employee records.
        // Phase B: employment contracts. Phase C: staff attendance, separate from `mark-attendance`/
        // `view-attendance-reports`, which govern STUDENT attendance. Phase D: leave management --
        // `leave-request` is self-service and goes to every staff tier, not just HR/admin. Phase E:
        // performance reviews -- no self-service "author your own" permission exists, since an employee
        // never authors their own review, only views a finalized one once it exists. Phase F: payroll
        // foundation -- same shape as performance, no self-service "run your own payroll" permission;
        // viewing your own finalized payslip needs no permission at all.)
        'employee-view',
        'employee-manage',
        'contract-view',
        'contract-manage',
        'staff-attendance-view',
        'staff-attendance-manage',
        'leave-view',
        'leave-request',
        'leave-approve',
        'performance-view',
        'performance-manage',
        'payroll-view',
        'payroll-manage',

        // Timetable (academic-side expansion, Phase 1 of 9: timetable scheduling -- a wholly new concept
        // layered on top of the EXISTING TeachingAssignment, which has no day/time component at all.
        // `timetable-override` is deliberately its own, narrower permission, separate from `-manage`,
        // since overriding a detected teacher/room/section conflict is a more sensitive action than
        // ordinary scheduling -- the brief's own words: "override only where explicitly permitted.")
        'timetable-view',
        'timetable-manage',
        'timetable-override',

        // Library (academic-side expansion, Phase 6 of 9). A librarian is any staff account these are
        // explicitly toggled on for, the same "not a new role" shape Inventory already established --
        // deliberately NOT in branch_admin's default bundle.
        'library-view',
        'library-manage',

        // Student discipline (academic-side expansion, Phase 7 of 9). Separate from manage-students, the
        // same explicit view/manage split Medical Records and HR already established.
        'discipline-view',
        'discipline-manage',
    ];

    /** How the permission screens group PERMISSIONS for reading (every permission is in exactly one group; a test pins that). */
    public const PERMISSION_GROUPS = [
        'school' => ['manage-branches', 'manage-school-settings', 'manage-grading-scale', 'manage-staff'],
        'people' => ['manage-students', 'manage-parents', 'manage-teachers', 'manage-classes', 'override-section-capacity'],
        'medical' => ['medical-records-view', 'medical-records-manage'],
        'academics' => ['mark-attendance', 'view-attendance-reports', 'enter-marks', 'approve-results', 'publish-results', 'manage-assignments'],
        'money' => ['manage-fee-structures', 'manage-invoices', 'verify-payments', 'view-financial-reports', 'refund-payments', 'approve-large-discounts'],
        'messages' => ['manage-announcements', 'manage-complaints', 'manage-calendar'],
        'oversight' => ['view-audit-log'],
        'inventory' => ['inventory-view', 'inventory-manage', 'stock-receive', 'stock-issue', 'stock-transfer', 'stock-adjust', 'requisition-create', 'requisition-approve', 'requisition-issue', 'asset-view', 'asset-manage'],
        'procurement' => ['supplier-manage', 'purchase-request-create', 'purchase-approve', 'purchase-approve-large', 'quotation-manage', 'purchase-order-manage', 'goods-receipt-manage', 'supplier-bill-manage', 'supplier-payment-manage'],
        'accounting' => ['accounting-view', 'accounting-manage', 'accounting-period-manage', 'accounting-period-reopen'],
        'hr' => ['employee-view', 'employee-manage', 'contract-view', 'contract-manage', 'staff-attendance-view', 'staff-attendance-manage', 'leave-view', 'leave-request', 'leave-approve', 'performance-view', 'performance-manage', 'payroll-view', 'payroll-manage'],
        'timetable' => ['timetable-view', 'timetable-manage', 'timetable-override'],
        'library' => ['library-view', 'library-manage'],
        'discipline' => ['discipline-view', 'discipline-manage'],
    ];

    /**
     * Default permission set granted to a NEW account when it's created,
     * per tier. This is a plain data map, not something attached to the
     * Spatie Role itself — Roles here carry zero permissions of their
     * own and exist only to identify the tier (hasRole() checks, UI
     * grouping). If a Role carried real permissions, every account with
     * that role would inherit them automatically via Spatie's role
     * inheritance, which would silently defeat per-account permission
     * toggling for the branch_admin tier (two accounts with the same
     * role would always end up with identical effective permissions
     * no matter what was toggled off for one of them). Keeping
     * permissions exclusively on the user — see User::assignTier() —
     * is what makes "same tier, different permissions" actually true.
     */
    public const DEFAULT_PERMISSIONS = [
        'owner' => self::PERMISSIONS,

        'branch_admin' => [
            'manage-school-settings',
            'manage-grading-scale',
            'manage-staff',
            'manage-students',
            'manage-parents',
            'manage-teachers',
            'manage-classes',
            'medical-records-view',
            'medical-records-manage',
            'discipline-view',
            'discipline-manage',
            'mark-attendance', // so a branch admin can cover for an absent class teacher
            'view-attendance-reports',
            'approve-results',
            'publish-results',
            'manage-assignments',
            'manage-fee-structures',
            'manage-invoices',
            'verify-payments',
            'view-financial-reports',
            'approve-large-discounts',
            'override-section-capacity',
            'manage-announcements',
            'manage-complaints',
            'manage-calendar',
            'employee-view',
            'employee-manage',
            'contract-view',
            'contract-manage',
            'staff-attendance-view',
            'staff-attendance-manage',
            'leave-view',
            'leave-request',
            'leave-approve',
            'performance-view',
            'performance-manage',
            'payroll-view',
            'payroll-manage',
            'timetable-view',
            'timetable-manage',
            'timetable-override',
        ],

        'teacher' => [
            'mark-attendance',
            'enter-marks',
            'manage-assignments',
            'leave-request',
        ],

        'accountant' => [
            'manage-fee-structures',
            'manage-invoices',
            'verify-payments',
            'view-financial-reports',
            'leave-request',
        ],

        // Parent and Student see only their own data via relationships,
        // not module permissions.
        'parent' => [],
        'student' => [],

        // Auditor: read-only, everywhere. Deliberately none of the
        // manage-* permissions — view-* only.
        'auditor' => [
            'view-attendance-reports',
            'view-financial-reports',
            'view-audit-log',
        ],
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        // Roles carry no permissions of their own — see the note on
        // DEFAULT_PERMISSIONS above for why.
        foreach (self::ROLES as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }
}
