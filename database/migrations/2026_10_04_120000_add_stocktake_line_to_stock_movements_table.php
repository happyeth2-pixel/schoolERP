<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Links an adjustment movement back to the stocktake line that caused it -- the same traceability pattern
 * as related_requisition_item_id, so "why did this change" always has a real answer. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('related_stocktake_line_id')->nullable()->after('related_requisition_item_id')->constrained('stocktake_lines')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('related_stocktake_line_id');
        });
    }
};
