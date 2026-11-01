<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\Enrollment;
use App\Models\School;
use App\Models\Section;
use App\Models\Student;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * One known-password login per actor tier, for handing a live trial over to real people (Paradise
 * Hills) without making them hunt through deploy logs for six separate random temporary passwords
 * the way the ordinary DemoSchoolSeeder flow does in production.
 *
 * Deliberately NOT wired to always run: it only does anything at all when TRIAL_PASSWORD is set in
 * the environment, and the password itself is read from there -- never hardcoded in this file, so it
 * never ends up sitting in a public git history. Set TRIAL_PASSWORD once in Render's (private)
 * Environment tab, then either redeploy (the Dockerfile's own startup chain calls this on every boot,
 * a no-op skip when the env var is absent) or run `php artisan trial:seed` by hand from Render's Shell.
 *
 * Safe to re-run: every account is found-or-created by a fixed email, and the password/role/status are
 * re-applied every time regardless, so changing TRIAL_PASSWORD and re-running resets it cleanly --
 * useful if a trial's credentials need to be rotated or handed to a different person later.
 */
class SeedTrialAccounts extends Command
{
    protected $signature = 'trial:seed';

    protected $description = 'Create/update one known-password account per actor tier for a live trial, from TRIAL_PASSWORD';

    public function handle(): int
    {
        $password = env('TRIAL_PASSWORD');

        if (! $password) {
            $this->line('TRIAL_PASSWORD is not set -- skipping (nothing to do).');

            return self::SUCCESS;
        }

        $school = School::first();
        if (! $school) {
            $this->warn('No school exists yet -- run this after the normal db:seed has created one.');

            return self::FAILURE;
        }

        $branch = Branch::where('school_id', $school->id)->where('is_main', true)->first()
            ?? Branch::where('school_id', $school->id)->first();

        $accounts = [
            ['role' => 'owner', 'title' => 'Owner (Trial)', 'name' => 'Trial Owner (Demo)', 'email' => 'trial.owner@paradisehills.test', 'phone' => '0911900001', 'branch_id' => null],
            ['role' => 'branch_admin', 'title' => 'Principal (Trial)', 'name' => 'Trial Principal (Demo)', 'email' => 'trial.admin@paradisehills.test', 'phone' => '0911900002', 'branch_id' => $branch?->id],
            ['role' => 'teacher', 'title' => 'Teacher (Trial)', 'name' => 'Trial Teacher (Demo)', 'email' => 'trial.teacher@paradisehills.test', 'phone' => '0911900003', 'branch_id' => $branch?->id],
            ['role' => 'accountant', 'title' => 'Accountant (Trial)', 'name' => 'Trial Accountant (Demo)', 'email' => 'trial.accountant@paradisehills.test', 'phone' => '0911900004', 'branch_id' => $branch?->id],
            ['role' => 'parent', 'title' => null, 'name' => 'Trial Parent (Demo)', 'email' => 'trial.parent@paradisehills.test', 'phone' => '0911900005', 'branch_id' => $branch?->id],
            ['role' => 'auditor', 'title' => 'Auditor (Trial)', 'name' => 'Trial Auditor (Demo)', 'email' => 'trial.auditor@paradisehills.test', 'phone' => '0911900006', 'branch_id' => null],
        ];

        $users = [];
        foreach ($accounts as $a) {
            $user = User::firstOrNew(['email' => $a['email']]);
            $user->fill([
                'school_id' => $school->id,
                'branch_id' => $a['branch_id'],
                'name' => $a['name'],
                'phone' => $a['phone'],
                'login_phone' => $a['phone'],
                'password' => $password,
                'must_change_password' => false,
                'status' => 'active',
                'email_verified_at' => $user->email_verified_at ?? now(),
            ]);
            $user->save();
            $user->assignTier($a['role'], $a['title']);
            $users[$a['role']] = $user;

            $this->line("{$a['role']} -> phone {$a['phone']}");
        }

        if ($branch) {
            $section = Section::whereHas('academicYear', fn ($q) => $q->where('is_current', true))
                ->where('branch_id', $branch->id)->first();

            $student = Student::firstOrCreate(
                ['student_id_number' => 'TRIAL-DEMO-001'],
                [
                    'school_id' => $school->id,
                    'branch_id' => $branch->id,
                    'first_name' => 'Trial',
                    'father_name' => 'Student',
                    'grandfather_name' => 'Demo',
                    'gender' => 'female',
                    'enrollment_date' => now()->toDateString(),
                    'status' => 'active',
                    'id_card_token' => Student::newCardToken(),
                ]
            );

            if (! $student->currentEnrollment && $section) {
                Enrollment::firstOrCreate(
                    ['student_id' => $student->id, 'section_id' => $section->id],
                    ['enrolled_on' => now()->toDateString(), 'status' => 'active']
                );
            }

            $parent = $users['parent'];
            if (! $parent->children()->where('student_id', $student->id)->exists()) {
                $parent->children()->attach($student->id, ['relationship' => 'guardian']);
            }
        }

        $this->info('Trial accounts ready. Every one above uses the password from TRIAL_PASSWORD.');

        return self::SUCCESS;
    }
}
