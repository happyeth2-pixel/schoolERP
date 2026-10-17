<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The unique key (student, academic year, frequency, period_start) is the
 * database-level definition of "one invoice per billing period". MySQL
 * treats NULLs as distinct inside a unique key, so a NULL period_start
 * would silently escape it -- make the column required.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Any legacy row without a period start belongs to the whole year.
        DB::statement('UPDATE invoices SET period_start = (SELECT start_date FROM academic_years WHERE academic_years.id = invoices.academic_year_id) WHERE period_start IS NULL');

        Schema::table('invoices', function (Blueprint $table) {
            $table->date('period_start')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->date('period_start')->nullable()->change();
        });
    }
};
