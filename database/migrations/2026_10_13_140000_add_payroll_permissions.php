<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;

/** HR module Phase F: payroll foundation. Like performance-view/performance-manage (and unlike
 * leave-request), there is no self-service "run your own payroll" permission -- payroll-view/payroll-manage
 * go only to existing owners and branch admins. Viewing your OWN finalized payslip needs no permission at
 * all, the same as My Leave/My Performance. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['payroll-view', 'payroll-manage'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        User::whereHas('roles', fn ($q) => $q->whereIn('name', ['owner', 'branch_admin']))->each(function (User $u) {
            $u->givePermissionTo(['payroll-view', 'payroll-manage']);
        });
    }

    public function down(): void
    {
        Permission::whereIn('name', ['payroll-view', 'payroll-manage'])->delete();
    }
};
