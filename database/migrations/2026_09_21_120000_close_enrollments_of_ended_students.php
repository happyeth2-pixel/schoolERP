<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data repair: before Student::changeStatus() existed, a student could be
 * marked graduated/withdrawn/etc. while their enrollment stayed "active",
 * so they kept showing up in rosters and invoice runs. Close those open
 * enrollments the same way changeStatus() would. Safe to re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        $closedAs = [
            'transferred' => 'transferred',
            'graduated' => 'completed',
            'withdrawn' => 'withdrawn',
            'expelled' => 'withdrawn',
            'inactive' => 'withdrawn',
        ];

        foreach ($closedAs as $studentStatus => $enrollmentStatus) {
            DB::table('enrollments')
                ->where('status', 'active')
                ->whereIn('student_id', DB::table('students')->where('status', $studentStatus)->select('id'))
                ->update(['status' => $enrollmentStatus, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // Not reversible: the original (contradictory) state isn't recorded.
    }
};
