<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;

/** HR module Phase E: performance reviews. Unlike leave-request, there is no self-service "author your own"
 * permission -- an employee never authors their own review, only views a finalized one once it exists -- so
 * `performance-view`/`performance-manage` go only to existing owners and branch admins, the same as every
 * other HR management permission before leave-request's self-service exception. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['performance-view', 'performance-manage'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        User::whereHas('roles', fn ($q) => $q->whereIn('name', ['owner', 'branch_admin']))->each(function (User $u) {
            $u->givePermissionTo(['performance-view', 'performance-manage']);
        });
    }

    public function down(): void
    {
        Permission::whereIn('name', ['performance-view', 'performance-manage'])->delete();
    }
};
