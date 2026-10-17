<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;

/** HR module Phase A: employee profiles, departments, positions. A brand-new `hr` permission group,
 * separate from `school`'s existing `manage-staff` (which governs login accounts/roles/permissions, not HR
 * employee records -- the two are deliberately decoupled per the brief). Granted to existing owners and
 * branch admins, matching how `manage-staff` is already available to both. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['employee-view', 'employee-manage'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        User::whereHas('roles', fn ($q) => $q->whereIn('name', ['owner', 'branch_admin']))->each(function (User $u) {
            $u->givePermissionTo(['employee-view', 'employee-manage']);
        });
    }

    public function down(): void
    {
        Permission::whereIn('name', ['employee-view', 'employee-manage'])->delete();
    }
};
