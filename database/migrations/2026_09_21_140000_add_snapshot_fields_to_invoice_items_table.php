<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An invoice line is a frozen copy of what was charged at the time:
 * description, quantity, unit amount and line total live on the row
 * itself. The fee/service ids are only a "where this came from"
 * reference (nulled if the fee is later deleted) -- no amount is ever
 * read back through them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->string('item_type', 20)->default('fee')->after('invoice_id');
            $table->foreignId('fee_structure_id')->nullable()->after('item_type')->constrained()->nullOnDelete();
            $table->foreignId('optional_service_id')->nullable()->after('fee_structure_id')->constrained()->nullOnDelete();
            $table->unsignedInteger('quantity')->default(1)->after('description');
            $table->decimal('unit_amount', 10, 2)->nullable()->after('quantity');
        });

        DB::table('invoice_items')->update(['unit_amount' => DB::raw('amount')]);
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fee_structure_id');
            $table->dropConstrainedForeignId('optional_service_id');
            $table->dropColumn(['item_type', 'quantity', 'unit_amount']);
        });
    }
};
