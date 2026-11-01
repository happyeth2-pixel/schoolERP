<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;

/** `parent-messages`: private messaging between a student's homeroom teacher/branch admin/owner and that
 * student's guardians (see create_parent_messages_table). Granted to every existing teacher and branch_admin
 * account, the same "grant to existing accounts of the tier that gets it by default" pattern
 * add_medical_records_permissions already established. */
return new class extends Migration
{
    public function up(): void
    {
        Permission::firstOrCreate(['name' => 'parent-messages', 'guard_name' => 'web']);

        User::whereHas('roles', fn ($q) => $q->whereIn('name', ['branch_admin', 'teacher']))->each(function (User $u) {
            $u->givePermissionTo('parent-messages');
        });
    }

    public function down(): void
    {
        Permission::where('name', 'parent-messages')->delete();
    }
};
