<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;

/** Academic expansion, Phase 6: `library-view`/`library-manage`. Deliberately NOT granted to `branch_admin`
 * by default -- the same precedent the Inventory module already set ("a storekeeper is any tier with
 * inventory permissions toggled on, not a new role"): a librarian is any staff account an owner explicitly
 * grants these to, not an assumed part of the standard branch_admin bundle. Owner already holds everything. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['library-view', 'library-manage'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
    }

    public function down(): void
    {
        Permission::whereIn('name', ['library-view', 'library-manage'])->delete();
    }
};
