<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Paying a supplier bill -- the mirror of `Payment` on the receivable side, but always born "confirmed":
 * there is no external party submitting proof for money going OUT, so unlike `Payment` there is no
 * pending/verify step. Append-only, same immutability philosophy as every ledger-adjacent record in this
 * app -- a mistake is corrected with a new, separate record, never an edit (there is deliberately no
 * refund/reversal concept on this side yet; a wrong payment is corrected with a manual journal entry until
 * a real need for one arises). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('supplier_bill_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('method')->default('bank_transfer'); // bank_transfer, mobile_money, cash, other
            $table->string('reference_number')->nullable();
            $table->foreignId('gl_cash_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->date('paid_date');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payments');
    }
};
