<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** One payslip per employee per payroll run -- a computed snapshot, never recalculated once its run is
 * finalized. Basic salary/gross/tax/pension/net are all stored here (not re-derived from the contract later)
 * so that a later contract change never silently rewrites a past, already-finalized payslip. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payslips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->decimal('basic_salary', 12, 2);
            $table->decimal('gross_pay', 12, 2);
            $table->decimal('taxable_income', 12, 2);
            $table->decimal('income_tax', 12, 2);
            $table->decimal('employee_pension', 12, 2);
            $table->decimal('employer_pension', 12, 2);
            $table->decimal('net_pay', 12, 2);
            $table->timestamps();

            $table->unique(['payroll_run_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payslips');
    }
};
