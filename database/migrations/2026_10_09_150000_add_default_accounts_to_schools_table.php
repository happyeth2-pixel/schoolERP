<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** How the posting engine finds "the" Accounts Receivable / Payable / cash-clearing / default revenue /
 * default expense / discount / waiver account WITHOUT hardcoding a code or name -- a school's chart of
 * accounts is its own (see App\Support\ChartOfAccountsTemplate), so the posting code asks the school which
 * of ITS accounts plays each role. Same shape as the school's existing settings columns
 * (purchase_approval_threshold, discount_limit_percent, ...). All nullable: nothing breaks for a school
 * that hasn't installed a chart of accounts yet -- postings simply cannot happen until these are set. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->foreignId('default_ar_account_id')->nullable()->after('waiver_limit_amount')->constrained('accounts')->nullOnDelete();
            $table->foreignId('default_ap_account_id')->nullable()->after('default_ar_account_id')->constrained('accounts')->nullOnDelete();
            $table->foreignId('default_cash_account_id')->nullable()->after('default_ap_account_id')->constrained('accounts')->nullOnDelete();
            $table->foreignId('default_revenue_account_id')->nullable()->after('default_cash_account_id')->constrained('accounts')->nullOnDelete();
            $table->foreignId('default_expense_account_id')->nullable()->after('default_revenue_account_id')->constrained('accounts')->nullOnDelete();
            $table->foreignId('default_discount_account_id')->nullable()->after('default_expense_account_id')->constrained('accounts')->nullOnDelete();
            $table->foreignId('default_waiver_account_id')->nullable()->after('default_discount_account_id')->constrained('accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_ar_account_id');
            $table->dropConstrainedForeignId('default_ap_account_id');
            $table->dropConstrainedForeignId('default_cash_account_id');
            $table->dropConstrainedForeignId('default_revenue_account_id');
            $table->dropConstrainedForeignId('default_expense_account_id');
            $table->dropConstrainedForeignId('default_discount_account_id');
            $table->dropConstrainedForeignId('default_waiver_account_id');
        });
    }
};
