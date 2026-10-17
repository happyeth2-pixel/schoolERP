<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * - payment_refunds: a verified payment is never edited or deleted; giving money back (or
 *   cancelling a wrong/duplicate payment) is a NEW row that points at it.
 * - invoices: who applied a discount / waiver and why, for the audit trail and thresholds.
 * - schools: the limits above which a discount or waiver needs an approver, and whether
 *   terms may overlap.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 10, 2);
            // refund   = money handed back to the payer
            // reversal = the payment should never have counted (duplicate, wrong invoice, never arrived)
            $table->enum('kind', ['refund', 'reversal'])->default('refund');
            $table->text('reason');
            $table->string('reference')->nullable();
            $table->foreignId('refunded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('refunded_at');
            $table->timestamps();

            $table->index(['payment_id']);
            $table->index('refunded_at');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->string('waiver_reason')->nullable()->after('is_waived');
            $table->foreignId('waived_by')->nullable()->after('waiver_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('waived_at')->nullable()->after('waived_by');
            $table->foreignId('discount_by')->nullable()->after('discount_reason')->constrained('users')->nullOnDelete();
        });

        Schema::table('schools', function (Blueprint $table) {
            $table->decimal('discount_limit_percent', 5, 2)->default(10);      // of the invoice subtotal
            $table->decimal('waiver_limit_amount', 10, 2)->default(1000);      // ETB of unpaid balance
            $table->boolean('allow_overlapping_terms')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('schools', fn (Blueprint $t) => $t->dropColumn(['discount_limit_percent', 'waiver_limit_amount', 'allow_overlapping_terms']));
        Schema::table('invoices', function (Blueprint $t) {
            $t->dropConstrainedForeignId('waived_by');
            $t->dropConstrainedForeignId('discount_by');
            $t->dropColumn(['waiver_reason', 'waived_at']);
        });
        Schema::dropIfExists('payment_refunds');
    }
};
