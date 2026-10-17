<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** One item's line in a stocktake. system_quantity is a snapshot taken when the count STARTS (what the
 * ledger said then); the actual adjustment at completion compares the physical count against the LIVE
 * balance at that moment (locked, via App\Services\Stock::adjust) -- so a movement that happens mid-count
 * is still reconciled correctly, never silently overwritten by a stale snapshot. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stocktake_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stocktake_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->integer('system_quantity');
            $table->integer('physical_quantity')->nullable();
            $table->string('reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stocktake_lines');
    }
};
