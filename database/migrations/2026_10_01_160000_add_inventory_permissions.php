<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;

/** Phase A of the inventory/stores module: catalog + stock ledger. New permissions, granted only to
 * existing owners (owner always holds every permission -- see RolesAndPermissionsSeeder::DEFAULT_PERMISSIONS).
 * Nobody else is auto-granted anything: a school turns these on per staff account, e.g. a branch_admin
 * titled "Storekeeper" given exactly stock-receive/stock-issue/stock-transfer and nothing else. */
return new class extends Migration
{
    public function up(): void
    {
        $names = ['inventory-view', 'inventory-manage', 'stock-receive', 'stock-issue', 'stock-transfer', 'stock-adjust'];

        foreach ($names as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        User::whereHas('roles', fn ($q) => $q->where('name', 'owner'))->each(fn (User $u) => $u->givePermissionTo($names));
    }

    public function down(): void
    {
        Permission::whereIn('name', ['inventory-view', 'inventory-manage', 'stock-receive', 'stock-issue', 'stock-transfer', 'stock-adjust'])->delete();
    }
};
