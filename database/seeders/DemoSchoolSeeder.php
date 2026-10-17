<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\School;
use App\Models\User;
use App\Support\PayrollTaxBracketDefaults;
use App\Support\TemporaryPassword;
use Illuminate\Database\Seeder;

class DemoSchoolSeeder extends Seeder
{
    /**
     * Seeds one pilot school with a main branch and one account per tier, so every dashboard can
     * be logged into and checked. This runs on every container boot in production (see the
     * Dockerfile's own comment) -- `firstOrCreate` makes that safe for the school/branch/accounts
     * themselves (a no-op once they exist), but it previously also meant a fixed, publicly-committed
     * password ("password") on a fully-privileged owner account was live in production forever.
     *
     * Outside production this keeps the old, convenient fixed "password" (used by hundreds of e2e
     * scripts and by hand during development). In production, each account is born with a genuinely
     * random `TemporaryPassword` and `must_change_password = true` -- the exact same mechanism an
     * admin's own "reset password" action already uses -- printed to the deploy log ONCE so whoever
     * is standing up the school can hand it over, then forced to be replaced at first real sign-in.
     * Because `firstOrCreate`'s attributes only apply on the INSERT, an account that already exists
     * from a previous boot is left untouched -- this never resets a password someone has since changed.
     */
    public function run(): void
    {
        $school = School::firstOrCreate(
            ['code' => 'DEMO'],
            [
                'name' => 'Demo Academy',
                'region' => 'Addis Ababa',
                'city' => 'Addis Ababa',
                'subcity' => 'Bole',
                'calendar_preference' => 'both',
            ]
        );

        $branch = Branch::firstOrCreate(
            ['school_id' => $school->id, 'code' => 'MAIN'],
            ['name' => 'Main Campus', 'is_main' => true]
        );

        // The payroll_tax_brackets migration only seeds schools that already exist when it runs -- this
        // is where every real school actually gets created (`db:seed`, after `migrate`), so it must install
        // the default brackets itself. install() is a no-op if the school already has any.
        PayrollTaxBracketDefaults::install($school);

        $accounts = [
            ['role' => 'owner', 'title' => 'Owner', 'name' => 'Selam Bekele', 'email' => 'owner@demo.test', 'phone' => '0911000001', 'branch_id' => null],
            ['role' => 'branch_admin', 'title' => 'Principal', 'name' => 'Almaz Tesfaye', 'email' => 'principal@demo.test', 'phone' => '0911000002', 'branch_id' => $branch->id],
            ['role' => 'teacher', 'title' => 'Teacher', 'name' => 'Samuel Girma', 'email' => 'teacher@demo.test', 'phone' => '0911000003', 'branch_id' => $branch->id],
            ['role' => 'accountant', 'title' => 'Accountant', 'name' => 'Meron Alemu', 'email' => 'accountant@demo.test', 'phone' => '0911000004', 'branch_id' => $branch->id],
            ['role' => 'parent', 'title' => null, 'name' => 'Kebede Worku', 'email' => 'parent@demo.test', 'phone' => '0911000005', 'branch_id' => $branch->id],
            ['role' => 'auditor', 'title' => 'Auditor', 'name' => 'Genet Fikru', 'email' => 'auditor@demo.test', 'phone' => '0911000006', 'branch_id' => null],
        ];

        $isProduction = app()->environment('production');

        foreach ($accounts as $account) {
            $password = $isProduction ? TemporaryPassword::make() : 'password';

            $user = User::firstOrCreate(
                ['email' => $account['email']],
                [
                    'school_id' => $school->id,
                    'branch_id' => $account['branch_id'],
                    'name' => $account['name'],
                    'phone' => $account['phone'],
                    'login_phone' => $account['phone'],
                    'password' => $password,
                    'must_change_password' => $isProduction,
                    'status' => 'active',
                    'email_verified_at' => now(),
                ]
            );

            $user->assignTier($account['role'], $account['title']);

            if ($isProduction && $user->wasRecentlyCreated) {
                $this->command?->warn("Demo account {$account['email']} created with temporary password: {$password}");
            }
        }
    }
}
