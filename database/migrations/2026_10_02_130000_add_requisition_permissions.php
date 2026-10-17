<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;

/** Phase B of the inventory/stores module: requisitions, approvals, issuing. Same rule as Phase A's
 * permissions -- granted only to existing owners; everyone else is turned on per account, deliberately. */
return new class extends Migration
{
    public function up(): void
    {
        $names = ['requisition-create', 'requisition-approve', 'requisition-issue'];

        foreach ($names as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        User::whereHas('roles', fn ($q) => $q->where('name', 'owner'))->each(fn (User $u) => $u->givePermissionTo($names));
    }

    public function down(): void
    {
        Permission::whereIn('name', ['requisition-create', 'requisition-approve', 'requisition-issue'])->delete();
    }
};
