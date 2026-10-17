<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A formal order sent to ONE supplier, built from an APPROVED purchase request -- optionally sourced from
 * a selected quotation's prices, but a quotation is not required (a small or sole-source purchase can skip
 * that phase entirely). `quotation_id` is kept only for traceability, never re-validated after creation.
 * Feeds Procurement Phase 4's Goods Receipt, which will match received quantities against this order's
 * own items -- the three-way check the brief asks for (request vs. order vs. receipt). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('purchase_request_id')->constrained()->restrictOnDelete();
            $table->foreignId('quotation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->string('number')->unique();
            $table->string('status')->default('draft'); // draft, sent, cancelled (Phase 4 adds received states)
            $table->date('order_date');
            $table->date('expected_delivery_date')->nullable();
            $table->string('delivery_info')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
    }
};
