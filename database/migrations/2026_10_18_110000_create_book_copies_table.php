<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Academic expansion, Phase 6: one row per PHYSICAL copy of a book -- branch-scoped (the same split `Room`
 * already uses for a branch-specific physical location), with its own current `status`. Deliberately NOT
 * modeled on the existing Inventory module's fungible `stock_balances` (a single quantity per item/store):
 * each copy is a distinct, individually trackable thing with its own lifecycle, not an interchangeable unit
 * in a count.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('book_copies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('copy_number');
            $table->enum('status', ['available', 'issued', 'lost', 'damaged'])->default('available');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['book_id', 'copy_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('book_copies');
    }
};
