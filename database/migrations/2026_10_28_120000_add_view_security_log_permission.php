<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;

/**
 * Splits the security log (failed logins, lockouts, masked identifiers -- see SecurityLogController) off
 * from the ordinary `view-audit-log` permission it used to share: someone individually toggled into the
 * plain audit trail for day-to-day record-keeping has no particular need to also see authentication-abuse
 * telemetry. Grandfathers every account that already holds `view-audit-log` at the moment this runs --
 * new accounts created afterward rely purely on RolesAndPermissionsSeeder::DEFAULT_PERMISSIONS, same as
 * every other permission added this way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Permission::firstOrCreate(['name' => 'view-security-log', 'guard_name' => 'web']);

        User::whereHas('permissions', fn ($q) => $q->where('name', 'view-audit-log'))
            ->each(fn (User $u) => $u->givePermissionTo('view-security-log'));
    }

    public function down(): void
    {
        Permission::where('name', 'view-security-log')->delete();
    }
};
