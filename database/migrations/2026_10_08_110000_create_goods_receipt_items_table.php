<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** One received line, always tied back to the purchase order line it fulfils -- the same "point at the same
 * original line" reasoning as `quotation_items`/`purchase_order_items`, which is what makes the three-way
 * check (request vs. order vs. receipt) possible: a line's total received quantity is summed live across
 * every receipt against it, never cached. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goods_receipt_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goods_receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_order_item_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity_received');
            $table->string('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_receipt_items');
    }
};
