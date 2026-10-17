<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The permanent history of one asset -- every assignment, transfer, repair, return, retirement and
 * disposal. Never edited or deleted (see AssetEvent::booted()), exactly like stock_movements: the asset's
 * own status/assigned_to/current_store_id are a snapshot, this table is the proof of how it got there. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->string('event_type');
            $table->foreignId('from_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('from_store_id')->nullable()->constrained('inventory_stores')->nullOnDelete();
            $table->foreignId('to_store_id')->nullable()->constrained('inventory_stores')->nullOnDelete();
            $table->string('notes')->nullable();
            $table->foreignId('performed_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_events');
    }
};
