<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The permanent, append-only ledger every stock change is written to -- never edited or deleted (see
 * StockMovement::booted()). A transfer is two rows (a transfer_out at the source, a transfer_in at the
 * destination) linked by related_movement_id, so every row still means "one store, one signed effect". */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('inventory_items')->cascadeOnDelete();
            $table->foreignId('store_id')->constrained('inventory_stores')->cascadeOnDelete();
            $table->string('type');
            $table->unsignedInteger('quantity');
            $table->string('reference_number')->nullable();
            $table->string('reason')->nullable();
            $table->foreignId('related_movement_id')->nullable()->constrained('stock_movements')->nullOnDelete();
            $table->foreignId('performed_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
