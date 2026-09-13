<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\School;
use App\Models\User;
use Illuminate\Database\Seeder;

class DemoSchoolSeeder extends Seeder
{
    /**
     * Seeds one pilot school with a main branch and one account per
     * tier, so every dashboard can be logged into and checked during
     * development. Password for every account below is "password".
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

        $accounts = [
            ['role' => 'owner', 'title' => 'Owner', 'name' => 'Selam Bekele', 'email' => 'owner@demo.test', 'branch_id' => null],
            ['role' => 'branch_admin', 'title' => 'Principal', 'name' => 'Almaz Tesfaye', 'email' => 'principal@demo.test', 'branch_id' => $branch->id],
            ['role' => 'teacher', 'title' => 'Teacher', 'name' => 'Samuel Girma', 'email' => 'teacher@demo.test', 'branch_id' => $branch->id],
            ['role' => 'accountant', 'title' => 'Accountant', 'name' => 'Meron Alemu', 'email' => 'accountant@demo.test', 'branch_id' => $branch->id],
            ['role' => 'parent', 'title' => null, 'name' => 'Kebede Worku', 'email' => 'parent@demo.test', 'branch_id' => $branch->id],
            ['role' => 'student', 'title' => null, 'name' => 'Abel Kebede', 'email' => 'student@demo.test', 'branch_id' => $branch->id],
            ['role' => 'auditor', 'title' => 'Auditor', 'name' => 'Genet Fikru', 'email' => 'auditor@demo.test', 'branch_id' => null],
        ];

        foreach ($accounts as $account) {
            $user = User::firstOrCreate(
                ['email' => $account['email']],
                [
                    'school_id' => $school->id,
                    'branch_id' => $account['branch_id'],
                    'name' => $account['name'],
                    'password' => 'password',
                    'status' => 'active',
                    'email_verified_at' => now(),
                ]
            );

            $user->assignTier($account['role'], $account['title']);
        }
    }
}
