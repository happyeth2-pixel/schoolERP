<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A book sale is a third kind of invoice line, alongside a fee and a service -- `item_type` is a plain
 *  string (not an enum), so no schema change was needed there; only the new source reference. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->foreignId('book_item_id')->nullable()->after('optional_service_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('book_item_id');
        });
    }
};
