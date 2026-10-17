<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;

/** Academic expansion, Phase 7: `discipline-view`/`discipline-manage`, deliberately separate from
 * `manage-students` -- the same explicit view/manage split Medical Records and HR's employee records
 * already established, so holding the broad student-admin permission alone never implies seeing behavioral
 * records. Granted to `branch_admin` by default -- handling discipline is an ordinary part of running a
 * branch (unlike Library, an optional specialist duty), the same reasoning Medical Records already used. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['discipline-view', 'discipline-manage'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        User::whereHas('roles', fn ($q) => $q->where('name', 'branch_admin'))->each(function (User $u) {
            $u->givePermissionTo(['discipline-view', 'discipline-manage']);
        });
    }

    public function down(): void
    {
        Permission::whereIn('name', ['discipline-view', 'discipline-manage'])->delete();
    }
};
