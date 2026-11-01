<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Guards CheckOverdueInvoices so a still-unpaid invoice is only ever flagged to staff once, the same
 * "don't spam" rule library_loans.overdue_notified_at already follows. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->timestamp('overdue_notified_at')->nullable()->after('waived_at');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', fn (Blueprint $t) => $t->dropColumn('overdue_notified_at'));
    }
};
