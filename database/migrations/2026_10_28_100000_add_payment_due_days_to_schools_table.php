<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many days after a billing period starts a family has to pay before an invoice counts as overdue
 * (see CheckOverdueInvoices) -- an owner-set limit the same way discount_limit_percent/waiver_limit_amount
 * already are, not a fixed rule baked into the code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->unsignedSmallInteger('payment_due_days')->default(14)->after('waiver_limit_amount');
        });
    }

    public function down(): void
    {
        Schema::table('schools', fn (Blueprint $t) => $t->dropColumn('payment_due_days'));
    }
};
