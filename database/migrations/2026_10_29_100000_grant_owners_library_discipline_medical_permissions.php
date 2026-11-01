<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;

/**
 * A real bug fix. Three earlier migrations -- 2026_10_17_110000 (medical records), 2026_10_18_140000
 * (library), 2026_10_19_110000 (discipline) -- each granted their new permissions to `branch_admin`
 * accounts (or, for library, to nobody at all) but never to `owner` accounts that already existed at the
 * time they ran, unlike every other permission-adding migration in this history. The library migration's
 * own comment even wrongly claimed "Owner already holds everything" -- Spatie permissions are explicit
 * grants, not something a new Permission row retroactively attaches to anyone; `DEFAULT_PERMISSIONS['owner']`
 * only ever applies at account-creation time, never live. Net effect: any owner account created before
 * each of those three migrations ran was permanently missing library/discipline/medical-records access,
 * with nothing in the UI explaining why -- reported directly by the owner of this very school.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permissions = [
            'library-view', 'library-manage',
            'discipline-view', 'discipline-manage',
            'medical-records-view', 'medical-records-manage',
        ];

        User::whereHas('roles', fn ($q) => $q->where('name', 'owner'))->each(function (User $u) use ($permissions) {
            $u->givePermissionTo($permissions);
        });
    }

    public function down(): void
    {
        // Deliberately a no-op: these are permissions an owner should always have had: this
        // migration should not accidentally remove owner access.
    }
};
