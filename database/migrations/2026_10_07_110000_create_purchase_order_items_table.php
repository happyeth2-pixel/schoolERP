<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** One ordered line, always tied back to the specific requested line it fulfils (never a free-text
 * description of its own -- same reasoning as `quotation_items`: it stays meaningful to compare against
 * the request, and later against a goods receipt, only by pointing at the same original line). A request
 * line's ordered quantity across every non-cancelled purchase order is checked live against how much was
 * actually requested -- never cached -- so a line can never be over-ordered, including when split across
 * more than one supplier. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_request_item_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 12, 2);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total', 12, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_items');
    }
};
