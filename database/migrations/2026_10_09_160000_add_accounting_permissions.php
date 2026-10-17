<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;

/** A NEW, separate permission group -- deliberately not folded into `money`. The existing money-* permissions
 * govern the fee/invoice/payment screens ordinary staff already use; these govern the chart of accounts,
 * manual journal entries and period locks, which nobody should reach just because they can view students
 * or verify a payment. Same rule as every phase so far -- granted only to existing owners; everyone else is
 * turned on per account. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['accounting-view', 'accounting-manage', 'accounting-period-manage', 'accounting-period-reopen'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        User::whereHas('roles', fn ($q) => $q->where('name', 'owner'))->each(function (User $u) {
            $u->givePermissionTo(['accounting-view', 'accounting-manage', 'accounting-period-manage', 'accounting-period-reopen']);
        });
    }

    public function down(): void
    {
        Permission::whereIn('name', ['accounting-view', 'accounting-manage', 'accounting-period-manage', 'accounting-period-reopen'])->delete();
    }
};
