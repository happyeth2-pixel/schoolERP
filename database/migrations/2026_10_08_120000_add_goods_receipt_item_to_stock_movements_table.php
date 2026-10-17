<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Same traceability pattern as `related_requisition_item_id` (Phase B) and `related_stocktake_line_id`
 * (Phase D): a purchase-receipt stock movement points back at the goods receipt line that caused it, so a
 * store's incoming stock can always be traced back to the purchase order (and the request) it fulfilled. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('related_goods_receipt_item_id')->nullable()->after('related_stocktake_line_id')->constrained('goods_receipt_items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('related_goods_receipt_item_id');
        });
    }
};
