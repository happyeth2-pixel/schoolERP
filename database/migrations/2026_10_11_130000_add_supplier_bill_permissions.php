<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;

/** Procurement Phase C (accounting payable side): supplier bills and payments. Same rule as every phase so
 * far -- granted only to existing owners; everyone else is turned on per account. These stay in the
 * `procurement` group (not `accounting`), the same way goods-receipt-manage did -- this is a procurement
 * action whose accounting effect is a side effect, exactly like the invoice/payment side never needed a new
 * `money` permission either. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['supplier-bill-manage', 'supplier-payment-manage'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        User::whereHas('roles', fn ($q) => $q->where('name', 'owner'))->each(function (User $u) {
            $u->givePermissionTo(['supplier-bill-manage', 'supplier-payment-manage']);
        });
    }

    public function down(): void
    {
        Permission::whereIn('name', ['supplier-bill-manage', 'supplier-payment-manage'])->delete();
    }
};
