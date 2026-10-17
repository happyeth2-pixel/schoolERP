<?php

use App\Models\School;
use App\Support\PayrollTaxBracketDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR module Phase F: payroll foundation. Editable per-school progressive income-tax brackets, seeded here
 * (for every school that already exists at migration time) with Ethiopia's CURRENT published schedule --
 * see `App\Support\PayrollTaxBracketDefaults`, the single source of truth for the actual figures, shared
 * with any code that creates a school afresh after this migration has already run.
 *
 * UNVERIFIED AGAINST CURRENT LAW -- flagged in the UI on every page that shows these brackets, per the
 * user's own explicit instruction from the start of the HR module initiative ("research and seed editable
 * Ethiopian statutory tax-rate defaults, clearly flagged verify before relying on this"). Tax law can change
 * again; a school's accountant should confirm these figures before this feature is relied on for real pay.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_tax_brackets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->decimal('min_amount', 12, 2);
            $table->decimal('max_amount', 12, 2)->nullable();
            $table->decimal('rate', 5, 4);
            $table->decimal('deduction', 12, 2)->default(0);
            $table->timestamps();
        });

        School::all()->each(fn (School $school) => PayrollTaxBracketDefaults::install($school));
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_tax_brackets');
    }
};
