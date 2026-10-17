<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;

/** HR module Phase C: staff attendance. Same rule as every phase so far -- granted only to existing owners
 * and branch admins, everyone else turned on per account. Stays in the `hr` group. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['staff-attendance-view', 'staff-attendance-manage'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        User::whereHas('roles', fn ($q) => $q->whereIn('name', ['owner', 'branch_admin']))->each(function (User $u) {
            $u->givePermissionTo(['staff-attendance-view', 'staff-attendance-manage']);
        });
    }

    public function down(): void
    {
        Permission::whereIn('name', ['staff-attendance-view', 'staff-attendance-manage'])->delete();
    }
};
