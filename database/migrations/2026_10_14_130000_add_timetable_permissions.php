<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;

/** Academic expansion, Phase 1 (Timetable). `timetable-override` goes to the same owners/branch admins as
 * `-view`/`-manage` by default (same trust level as `approve-large-discounts` already gets) -- an owner can
 * always untick it for a specific, more restricted branch_admin account afterward. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['timetable-view', 'timetable-manage', 'timetable-override'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        User::whereHas('roles', fn ($q) => $q->whereIn('name', ['owner', 'branch_admin']))->each(function (User $u) {
            $u->givePermissionTo(['timetable-view', 'timetable-manage', 'timetable-override']);
        });
    }

    public function down(): void
    {
        Permission::whereIn('name', ['timetable-view', 'timetable-manage', 'timetable-override'])->delete();
    }
};
