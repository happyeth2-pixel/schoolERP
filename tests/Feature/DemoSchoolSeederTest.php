<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DemoSchoolSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Production-readiness audit finding: `DemoSchoolSeeder` runs on every container boot (the
 * Dockerfile's own comment says so), and used to give the fully-privileged `owner@demo.test`
 * account a fixed, publicly-committed password ("password") forever, in every environment
 * including production. Outside production the fixed password is kept (hundreds of e2e scripts
 * and manual testing depend on it); in production each account must instead get a genuinely
 * random temporary password that forces a real change at first sign-in.
 */
class DemoSchoolSeederTest extends TestCase
{
    use RefreshDatabase;

    private function seedForced(string $class): void
    {
        // db:seed's own ConfirmableTrait asks to confirm when the (faked) environment is
        // production; --force bypasses that the same way the Dockerfile's real boot command does.
        $this->artisan('db:seed', ['--class' => $class, '--force' => true, '--no-interaction' => true]);
    }

    /** Swaps only the app container's own 'env' binding -- never refreshApplication(), which would
     * tear down the in-memory SQLite connection this test's schema already lives on. */
    private function fakeProduction(callable $callback): void
    {
        $previous = $this->app['env'];

        try {
            $this->app->instance('env', 'production');
            $this->assertTrue(app()->environment('production'));

            $callback();
        } finally {
            $this->app->instance('env', $previous);
        }
    }

    public function test_outside_production_the_accounts_use_the_fixed_known_password(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(DemoSchoolSeeder::class);

        $owner = User::where('email', 'owner@demo.test')->firstOrFail();
        $this->assertTrue(Hash::check('password', $owner->password));
        $this->assertFalse((bool) $owner->must_change_password);
    }

    public function test_in_production_each_account_gets_a_random_temporary_password_that_must_be_changed(): void
    {
        $this->fakeProduction(function () {
            $this->seedForced(RolesAndPermissionsSeeder::class);
            $this->seedForced(DemoSchoolSeeder::class);

            $owner = User::where('email', 'owner@demo.test')->firstOrFail();
            $this->assertFalse(Hash::check('password', $owner->password), 'the known fixed password must never work in production');
            $this->assertTrue((bool) $owner->must_change_password);

            $auditor = User::where('email', 'auditor@demo.test')->firstOrFail();
            $this->assertNotSame($owner->password, $auditor->password, 'each account gets its own random password, not one shared value');
        });
    }

    public function test_rerunning_in_production_never_resets_an_existing_accounts_password(): void
    {
        $this->fakeProduction(function () {
            $this->seedForced(RolesAndPermissionsSeeder::class);
            $this->seedForced(DemoSchoolSeeder::class);
            $firstHash = User::where('email', 'owner@demo.test')->firstOrFail()->password;

            // simulates the next container boot re-running db:seed --force
            $this->seedForced(DemoSchoolSeeder::class);
            $secondHash = User::where('email', 'owner@demo.test')->firstOrFail()->password;

            $this->assertSame($firstHash, $secondHash, 'firstOrCreate must never overwrite a password someone may have since changed');
        });
    }
}
