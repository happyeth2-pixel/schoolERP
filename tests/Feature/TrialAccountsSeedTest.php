<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

/** php artisan trial:seed -- one known-password account per actor tier, for handing a live trial to
 * real people without hunting through deploy logs for random generated passwords. */
class TrialAccountsSeedTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    public function test_it_does_nothing_when_trial_password_is_not_set(): void
    {
        $this->buildSchool();
        putenv('TRIAL_PASSWORD');

        $this->artisan('trial:seed')->assertExitCode(0);

        $this->assertDatabaseMissing('users', ['email' => 'trial.owner@paradisehills.test']);
    }

    public function test_it_creates_one_working_account_per_role_when_set(): void
    {
        $this->buildSchool();
        putenv('TRIAL_PASSWORD=SomeTrialPass9');

        $this->artisan('trial:seed')->assertExitCode(0);

        $emailSlugs = ['owner' => 'owner', 'branch_admin' => 'admin', 'teacher' => 'teacher', 'accountant' => 'accountant', 'parent' => 'parent', 'auditor' => 'auditor'];
        foreach ($emailSlugs as $role => $slug) {
            $user = User::where('email', "trial.{$slug}@paradisehills.test")->first();
            $this->assertNotNull($user, "missing {$role}");
            $this->assertTrue($user->hasRole($role));
            $this->assertTrue(Hash::check('SomeTrialPass9', $user->password));
            $this->assertFalse((bool) $user->must_change_password);
            $this->assertSame('active', $user->status);
        }

        $parent = User::where('email', 'trial.parent@paradisehills.test')->first();
        $this->assertSame(1, $parent->children()->count());

        putenv('TRIAL_PASSWORD');
    }

    public function test_re_running_with_a_new_password_updates_the_existing_accounts(): void
    {
        $this->buildSchool();
        putenv('TRIAL_PASSWORD=FirstPassword1');
        $this->artisan('trial:seed');

        putenv('TRIAL_PASSWORD=SecondPassword2');
        $this->artisan('trial:seed')->assertExitCode(0);

        $owner = User::where('email', 'trial.owner@paradisehills.test')->first();
        $this->assertTrue(Hash::check('SecondPassword2', $owner->password));
        $this->assertSame(1, User::where('email', 'trial.owner@paradisehills.test')->count(), 'no duplicate created');

        putenv('TRIAL_PASSWORD');
    }
}
