<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A fourth billing frequency for a charge that is never recurring -- a one-off book sale today, and
 * anything else "charge once, right now" in future. Deliberately NOT reusing 'annual'/'termly'/'monthly':
 * those are the real dedup key invoice-generation already relies on (student, year, frequency, period_start
 * must be unique), and a one-time sale's period_start is "today", which could otherwise coincide with a
 * real annual/termly/monthly period's own start date for the same student and collide. See BookSales for
 * the one place that creates a 'one_time' invoice.
 *
 * Postgres gets its own path: Laravel's enum()->change() compiles to a single
 * `ALTER COLUMN ... TYPE varchar(255) CHECK (...)` statement on the pgsql grammar, which Postgres
 * rejects outright -- a CHECK constraint can't ride along with a TYPE change in one clause there, it
 * has to be its own ADD CONSTRAINT after dropping whatever the column's current check is named
 * (found dynamically via pg_constraint rather than assumed, since Postgres's auto-generated name for
 * it isn't guaranteed). MySQL and SQLite (every test run) keep the original, already-working path.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            $this->setPgsqlFrequencyCheck(['annual', 'termly', 'monthly', 'one_time']);

            return;
        }

        Schema::table('invoices', function (Blueprint $table) {
            $table->enum('frequency', ['annual', 'termly', 'monthly', 'one_time'])->default('annual')->change();
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            $this->setPgsqlFrequencyCheck(['annual', 'termly', 'monthly']);

            return;
        }

        Schema::table('invoices', function (Blueprint $table) {
            $table->enum('frequency', ['annual', 'termly', 'monthly'])->default('annual')->change();
        });
    }

    /** @param list<string> $values */
    private function setPgsqlFrequencyCheck(array $values): void
    {
        $existing = DB::selectOne("
            SELECT conname FROM pg_constraint
            WHERE conrelid = 'invoices'::regclass AND contype = 'c'
              AND pg_get_constraintdef(oid) LIKE '%frequency%'
        ");

        if ($existing) {
            DB::statement('ALTER TABLE invoices DROP CONSTRAINT "'.$existing->conname.'"');
        }

        $list = implode(', ', array_map(fn ($v) => "'{$v}'", $values));
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_frequency_check CHECK (frequency IN ({$list}))");
    }
};
