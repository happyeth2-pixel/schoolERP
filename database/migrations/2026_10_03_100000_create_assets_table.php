<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A durable, individually-tracked piece of school property -- a computer, a desk, a vehicle. Deliberately
 * never inventory_items: a consumable is counted, an asset is the one specific thing (its own tag, its own
 * history of who had it). category_id reuses inventory_categories with kind='asset' -- the same simple
 * named-grouping feature Phase A already built, not a second one. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('inventory_categories')->nullOnDelete();
            $table->string('asset_tag')->unique();
            $table->string('name');
            $table->string('serial_number')->nullable();
            $table->string('barcode')->nullable();
            $table->date('purchase_date')->nullable();
            $table->decimal('acquisition_cost', 12, 2)->nullable();
            $table->string('supplier')->nullable();
            $table->foreignId('current_store_id')->nullable()->constrained('inventory_stores')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('condition', ['good', 'fair', 'poor'])->default('good');
            $table->enum('status', ['available', 'assigned', 'under_repair', 'lost', 'damaged', 'retired', 'disposed'])->default('available');
            $table->date('warranty_expiry')->nullable();
            $table->text('notes')->nullable();
            $table->json('attachments')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};
