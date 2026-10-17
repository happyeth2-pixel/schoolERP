<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;

/** HR module Phase B: employment contracts. Same rule as every phase so far -- granted only to existing
 * owners and branch admins (matching how employee-view/employee-manage were granted in Phase A), everyone
 * else turned on per account. Stays in the `hr` group. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['contract-view', 'contract-manage'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        User::whereHas('roles', fn ($q) => $q->whereIn('name', ['owner', 'branch_admin']))->each(function (User $u) {
            $u->givePermissionTo(['contract-view', 'contract-manage']);
        });
    }

    public function down(): void
    {
        Permission::whereIn('name', ['contract-view', 'contract-manage'])->delete();
    }
};
