<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;

/** Phase C of the inventory/stores module: fixed assets. Same rule as Phases A and B -- granted only to
 * existing owners; everyone else is turned on per account, deliberately. */
return new class extends Migration
{
    public function up(): void
    {
        $names = ['asset-view', 'asset-manage'];

        foreach ($names as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        User::whereHas('roles', fn ($q) => $q->where('name', 'owner'))->each(fn (User $u) => $u->givePermissionTo($names));
    }

    public function down(): void
    {
        Permission::whereIn('name', ['asset-view', 'asset-manage'])->delete();
    }
};
