<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\NumberSequence;
use App\Models\School;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-time, explicitly-triggered catch-up: creates an HR Employee record for every existing staff User who
 * doesn't have one yet (every school adopting the HR module already has staff accounts from before it
 * existed). Mirrors `accounting:backfill-ledger`'s own shape -- idempotent via `whereDoesntHave('employee')`,
 * safe to re-run any time a new staff account is created before someone gets around to giving it its own
 * full HR profile.
 *
 * Only the fields already known from the account (name, phone, email, branch) are copied over; everything
 * genuinely HR-specific (department, position, date of birth, emergency contact, qualifications...) is left
 * blank for HR to fill in -- this command never invents data it doesn't have. `hire_date` falls back to the
 * account's own `created_at` date, the closest honest proxy available.
 */
class BackfillEmployees extends Command
{
    protected $signature = 'hr:backfill-employees {--school= : limit to one school ID} {--dry-run : report what would be created without creating anything}';

    protected $description = 'Create an Employee HR record for every existing staff User that does not have one yet.';

    private const STAFF_ROLES = ['owner', 'branch_admin', 'teacher', 'accountant', 'auditor'];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $schoolId = $this->option('school');

        $schools = School::when($schoolId, fn ($q) => $q->where('id', $schoolId))->get();
        if ($schools->isEmpty()) {
            $this->warn('No matching school found.');

            return self::SUCCESS;
        }

        foreach ($schools as $school) {
            $this->backfillSchool($school, $dryRun);
        }

        return self::SUCCESS;
    }

    private function backfillSchool(School $school, bool $dryRun): void
    {
        $users = User::where('school_id', $school->id)
            ->whereHas('roles', fn ($q) => $q->whereIn('name', self::STAFF_ROLES))
            ->whereDoesntHave('employee')
            ->get();

        if ($users->isEmpty()) {
            $this->info("{$school->name}: nothing to backfill, every staff account already has an employee record.");

            return;
        }

        $this->info("{$school->name}: {$users->count()} staff account(s) without an employee record.");

        if ($dryRun) {
            foreach ($users as $u) {
                $this->line("  would create: {$u->name}");
            }

            return;
        }

        $actor = User::where('school_id', $school->id)->whereHas('roles', fn ($q) => $q->where('name', 'owner'))->first();

        DB::transaction(function () use ($school, $users, $actor) {
            foreach ($users as $u) {
                Employee::create([
                    'school_id' => $school->id,
                    'branch_id' => $u->branch_id,
                    'user_id' => $u->id,
                    'employee_number' => $this->nextEmployeeNumber($school->id),
                    'full_name' => $u->name,
                    'phone' => $u->phone ?? $u->login_phone ?? '',
                    'email' => $u->email,
                    'hire_date' => $u->created_at->toDateString(),
                    'employment_status' => 'active',
                    'employment_type' => 'full_time',
                    'created_by' => ($actor ?? $u)->id,
                ]);
                $this->line("  created: {$u->name}");
            }
        });
    }

    private function nextEmployeeNumber(int $schoolId): string
    {
        $year = (int) date('Y');
        $prefix = "EMP-{$year}-";

        $n = NumberSequence::next($schoolId, 'employee', $year, fn () => Employee::where('school_id', $schoolId)
            ->where('employee_number', 'like', "{$prefix}%")
            ->pluck('employee_number')
            ->map(fn ($x) => (int) substr($x, strlen($prefix)))
            ->max() ?? 0);

        return $prefix.str_pad((string) $n, 6, '0', STR_PAD_LEFT);
    }
}
