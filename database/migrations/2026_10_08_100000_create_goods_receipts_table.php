<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A physical delivery against a SENT (or already partly received) purchase order -- a fait accompli, unlike
 * the quotation/purchase-order documents above it: once goods are physically received and stock has moved,
 * the record is never edited or removed (see GoodsReceipt::booted()), only corrected with a further stock
 * movement (a return), the same philosophy as `stock_movements`/`asset_events`. One purchase order can have
 * more than one receipt (a partial delivery followed by the rest later). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('purchase_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_id')->constrained('inventory_stores')->restrictOnDelete();
            $table->string('number')->unique();
            $table->date('received_date');
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_receipts');
    }
};
