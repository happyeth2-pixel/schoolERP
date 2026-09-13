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

        // Communication
        'manage-announcements',

        // Oversight
        'view-audit-log',
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
            'view-attendance-reports',
            'approve-results',
            'publish-results',
            'manage-assignments',
            'manage-fee-structures',
            'manage-invoices',
            'verify-payments',
            'view-financial-reports',
            'manage-announcements',
        ],

        'teacher' => [
            'mark-attendance',
            'enter-marks',
            'manage-assignments',
        ],

        'accountant' => [
            'manage-fee-structures',
            'manage-invoices',
            'verify-payments',
            'view-financial-reports',
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
