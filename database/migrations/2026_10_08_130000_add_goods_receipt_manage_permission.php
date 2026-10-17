<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;

/** Procurement Phase 4: goods receipt. Same rule as every phase so far -- granted only to existing owners;
 * everyone else is turned on per account. */
return new class extends Migration
{
    public function up(): void
    {
        Permission::firstOrCreate(['name' => 'goods-receipt-manage', 'guard_name' => 'web']);

        User::whereHas('roles', fn ($q) => $q->where('name', 'owner'))->each(fn (User $u) => $u->givePermissionTo('goods-receipt-manage'));
    }

    public function down(): void
    {
        Permission::where('name', 'goods-receipt-manage')->delete();
    }
};
