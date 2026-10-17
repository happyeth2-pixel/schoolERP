<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The fast, lockable running total for one (item, store) pair. Never edited directly -- only
 * App\Services\Stock changes it, inside the same transaction as the stock_movements row that justifies it
 * (see that service's docblock for why a locked read is what makes negative-stock prevention race-safe). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('inventory_items')->cascadeOnDelete();
            $table->foreignId('store_id')->constrained('inventory_stores')->cascadeOnDelete();
            $table->integer('quantity')->default(0);
            $table->timestamps();
            $table->unique(['item_id', 'store_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_balances');
    }
};
