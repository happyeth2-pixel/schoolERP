<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;

/** Academic expansion, Phase 4: `medical-records-view`/`medical-records-manage`, deliberately SEPARATE from
 * `manage-students` -- the exact same explicit-permission split `employee-view`/`employee-manage` already
 * established for HR, so holding the broad student-admin permission alone never implies seeing health data.
 * Granted to `branch_admin` by default, matching the HR module's own precedent for branch-sensitive data a
 * branch admin needs day to day (contracts, performance, payroll) -- an owner can always untick it for a
 * specific account afterward. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['medical-records-view', 'medical-records-manage'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        User::whereHas('roles', fn ($q) => $q->where('name', 'branch_admin'))->each(function (User $u) {
            $u->givePermissionTo(['medical-records-view', 'medical-records-manage']);
        });
    }

    public function down(): void
    {
        Permission::whereIn('name', ['medical-records-view', 'medical-records-manage'])->delete();
    }
};
