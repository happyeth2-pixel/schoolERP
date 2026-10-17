<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `SupplierPayment`'s own docblock already disclosed this gap: "a mistake is corrected with a new record
 * (or, until a dedicated reversal exists on this side, a manual journal entry)." This is that dedicated
 * reversal, built as the exact mirror of the already-proven `payment_refunds` table (the parent-facing
 * receivable side) -- same shape, same `kind` split, same append-only discipline: a supplier payment is
 * never edited or deleted; giving money back or cancelling a wrong/duplicate one is a NEW row that points
 * at it, never a change to the original.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_payment_reversals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_payment_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            // refund   = money handed back by the supplier
            // reversal = the payment should never have counted (duplicate, wrong bill, sent in error)
            $table->enum('kind', ['refund', 'reversal'])->default('reversal');
            $table->text('reason');
            $table->string('reference')->nullable();
            $table->foreignId('reversed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('reversed_at');
            $table->timestamps();

            $table->index(['supplier_payment_id']);
            $table->index('reversed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payment_reversals');
    }
};
