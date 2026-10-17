<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Which revenue account an invoice line posts its credit to -- nullable, so an unmapped fee or service
 * falls back to the school's own `default_revenue_account_id` (see App\Services\AccountingPostings) and
 * nothing breaks for a school that hasn't set this up per fee. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fee_structures', function (Blueprint $table) {
            $table->foreignId('revenue_account_id')->nullable()->after('frequency')->constrained('accounts')->nullOnDelete();
        });
        Schema::table('optional_services', function (Blueprint $table) {
            $table->foreignId('revenue_account_id')->nullable()->after('frequency')->constrained('accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('fee_structures', fn (Blueprint $table) => $table->dropConstrainedForeignId('revenue_account_id'));
        Schema::table('optional_services', fn (Blueprint $table) => $table->dropConstrainedForeignId('revenue_account_id'));
    }
};
