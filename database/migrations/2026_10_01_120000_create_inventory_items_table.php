<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A consumable's catalog entry -- stationery, paper, toner, uniforms and the like. School-wide, like
 * FeeStructure: the SAME item can be stocked in several stores, each with its own stock_balances row.
 * Fixed assets (computers, furniture, vehicles) are a deliberately separate table -- never this one. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('inventory_categories')->nullOnDelete();
            $table->string('name');
            $table->string('sku')->nullable();
            $table->string('barcode')->nullable();
            $table->string('unit')->default('pcs');
            $table->text('description')->nullable();
            $table->unsignedInteger('min_stock_level')->default(0);
            $table->unsignedInteger('reorder_level')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_items');
    }
};
