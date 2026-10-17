<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;

/** HR module Phase D: leave management. `leave-view`/`leave-approve` go to existing owners and branch admins,
 * same as every HR permission so far. `leave-request` is different -- confirmed via AskUserQuestion that
 * leave is self-service for anyone with a linked account, so EVERY existing staff account (teacher,
 * accountant, branch admin, owner) gets it, not just HR/admin tiers. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['leave-view', 'leave-request', 'leave-approve'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        User::whereHas('roles', fn ($q) => $q->whereIn('name', ['owner', 'branch_admin']))->each(function (User $u) {
            $u->givePermissionTo(['leave-view', 'leave-request', 'leave-approve']);
        });

        User::whereHas('roles', fn ($q) => $q->whereIn('name', ['teacher', 'accountant']))->each(function (User $u) {
            $u->givePermissionTo(['leave-request']);
        });
    }

    public function down(): void
    {
        Permission::whereIn('name', ['leave-view', 'leave-request', 'leave-approve'])->delete();
    }
};
