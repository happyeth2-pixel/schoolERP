<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;

/** Procurement Phase 1: suppliers, purchase requests, approval. Same rule as every inventory-module phase
 * -- new permissions granted only to existing owners; everyone else is turned on per account. */
return new class extends Migration
{
    public function up(): void
    {
        $names = ['supplier-manage', 'purchase-request-create', 'purchase-approve', 'purchase-approve-large'];

        foreach ($names as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        User::whereHas('roles', fn ($q) => $q->where('name', 'owner'))->each(fn (User $u) => $u->givePermissionTo($names));

        Schema::table('schools', function (Blueprint $table) {
            // null = no two-tier distinction yet: every purchase request only ever needs purchase-approve,
            // until a school sets this and purchase-approve-large starts being required above it.
            $table->decimal('purchase_approval_threshold', 12, 2)->nullable()->after('discount_limit_percent');
        });
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn('purchase_approval_threshold');
        });

        Permission::whereIn('name', ['supplier-manage', 'purchase-request-create', 'purchase-approve', 'purchase-approve-large'])->delete();
    }
};
