<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;

/** Procurement Phase 3: purchase orders. Same rule as every phase so far -- granted only to existing
 * owners; everyone else is turned on per account. */
return new class extends Migration
{
    public function up(): void
    {
        Permission::firstOrCreate(['name' => 'purchase-order-manage', 'guard_name' => 'web']);

        User::whereHas('roles', fn ($q) => $q->where('name', 'owner'))->each(fn (User $u) => $u->givePermissionTo('purchase-order-manage'));
    }

    public function down(): void
    {
        Permission::where('name', 'purchase-order-manage')->delete();
    }
};
