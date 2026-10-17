<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Which ledger cash/bank account a payment's money actually landed in -- distinct from the EXISTING
 * `PaymentAccount` model (display-only metadata shown to a parent: "pay into this account"). Nullable: an
 * unmapped payment posts to the school's own `default_cash_account_id` ("Undeposited Funds"), the standard
 * accounting pattern for money not yet matched to a specific bank deposit. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('gl_cash_account_id')->nullable()->after('channel')->constrained('accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', fn (Blueprint $table) => $table->dropConstrainedForeignId('gl_cash_account_id'));
    }
};
