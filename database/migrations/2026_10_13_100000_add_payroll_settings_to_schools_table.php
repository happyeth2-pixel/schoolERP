<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** HR module Phase F: payroll foundation. Pension rates live directly on `schools`, the same way
 * `discount_limit_percent`/`purchase_approval_threshold`/`waiver_limit_amount` already do -- a single
 * editable value per school, not a whole table. Seeded with the current Private Organization Employees'
 * Pension Proclamation No. 1268/2022 rates (employee 7%, employer 11%) -- editable, and flagged in the UI to
 * verify against current law before relying on it, per the user's own instruction from the start of this
 * module. The five new default_*_account_id columns follow the EXACT existing pattern
 * (default_ar_account_id etc.) -- found by role via ChartOfAccountsTemplate, never a hardcoded account. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->decimal('pension_employee_rate', 5, 4)->default(0.07)->after('waiver_limit_amount');
            $table->decimal('pension_employer_rate', 5, 4)->default(0.11)->after('pension_employee_rate');

            $table->foreignId('default_salary_expense_account_id')->nullable()->after('default_waiver_account_id')->constrained('accounts')->nullOnDelete();
            $table->foreignId('default_pension_expense_account_id')->nullable()->after('default_salary_expense_account_id')->constrained('accounts')->nullOnDelete();
            $table->foreignId('default_tax_payable_account_id')->nullable()->after('default_pension_expense_account_id')->constrained('accounts')->nullOnDelete();
            $table->foreignId('default_pension_payable_account_id')->nullable()->after('default_tax_payable_account_id')->constrained('accounts')->nullOnDelete();
            $table->foreignId('default_salary_payable_account_id')->nullable()->after('default_pension_payable_account_id')->constrained('accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_salary_expense_account_id');
            $table->dropConstrainedForeignId('default_pension_expense_account_id');
            $table->dropConstrainedForeignId('default_tax_payable_account_id');
            $table->dropConstrainedForeignId('default_pension_payable_account_id');
            $table->dropConstrainedForeignId('default_salary_payable_account_id');
            $table->dropColumn(['pension_employee_rate', 'pension_employer_rate']);
        });
    }
};
