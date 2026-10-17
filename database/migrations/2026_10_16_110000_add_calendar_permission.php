<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;

/** Academic expansion, Phase 3: a single `manage-calendar` permission, mirroring `manage-announcements`
 * exactly (create/edit/remove gated; viewing an event you're the audience for needs no permission at all).
 * Granted to `branch_admin` by default, same as `manage-announcements` -- owner already holds everything. */
return new class extends Migration
{
    public function up(): void
    {
        Permission::firstOrCreate(['name' => 'manage-calendar', 'guard_name' => 'web']);

        User::whereHas('roles', fn ($q) => $q->where('name', 'branch_admin'))->each(function (User $u) {
            $u->givePermissionTo('manage-calendar');
        });
    }

    public function down(): void
    {
        Permission::where('name', 'manage-calendar')->delete();
    }
};
