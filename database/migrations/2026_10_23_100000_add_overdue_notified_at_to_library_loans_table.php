<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Library had no overdue reminder of any kind -- confirmed as a real, disclosed gap in the original
 * research pass ("no overdue-loan reminder, no fine notification... checked specifically, not assumed
 * absent"). This guard column is the same "notify exactly once per crossing, never repeat" pattern already
 * used by `employment_contracts.expiry_notified` and inventory's own low-stock alert: set the first time a
 * loan is found overdue, cleared again on renewal so a loan that goes overdue a SECOND time (after being
 * renewed) is treated as a new event worth notifying about again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('library_loans', function (Blueprint $table) {
            $table->timestamp('overdue_notified_at')->nullable()->after('fine_paid_at');
        });
    }

    public function down(): void
    {
        Schema::table('library_loans', function (Blueprint $table) {
            $table->dropColumn('overdue_notified_at');
        });
    }
};
