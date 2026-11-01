<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

/**
 * A real bug, reported directly by an owner who could not open Library/Discipline/Medical Records:
 * three earlier migrations (medical records, library, discipline) granted their new permissions to
 * `branch_admin` but never to `owner` accounts that already existed when they ran -- unlike every other
 * permission-adding migration in this history. The fix migration
 * (2026_10_29_100000_grant_owners_library_discipline_medical_permissions) backfills them. This pins that
 * fix by simulating the exact historical bug state (an owner missing those six permissions) and running
 * the fix migration's own up() directly, rather than relying on the seeder's current PERMISSIONS list --
 * which would pass even if the fix migration were deleted, since a freshly-created test owner always gets
 * today's full permission catalogue regardless.
 */
class OwnerPermissionBackfillTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    public function test_the_backfill_migration_grants_the_six_permissions_to_an_owner_missing_them(): void
    {
        $this->buildSchool();

        $stale = ['library-view', 'library-manage', 'discipline-view', 'discipline-manage', 'medical-records-view', 'medical-records-manage'];
        $this->owner->revokePermissionTo($stale);

        foreach ($stale as $permission) {
            $this->assertFalse($this->owner->fresh()->can($permission), "owner should not yet have {$permission}");
        }

        (require database_path('migrations/2026_10_29_100000_grant_owners_library_discipline_medical_permissions.php'))->up();

        $fresh = $this->owner->fresh();
        foreach ($stale as $permission) {
            $this->assertTrue($fresh->can($permission), "owner should now have {$permission}");
        }
    }

    public function test_a_brand_new_owner_account_already_has_all_six_by_default(): void
    {
        $this->buildSchool();

        foreach (['library-view', 'library-manage', 'discipline-view', 'discipline-manage', 'medical-records-view', 'medical-records-manage'] as $permission) {
            $this->assertTrue($this->owner->can($permission));
        }
    }
}
