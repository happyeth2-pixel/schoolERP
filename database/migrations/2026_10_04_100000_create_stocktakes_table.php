<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A physical count of one store's stock, against what the system says is there. "Draft" from the brief is
 * skipped -- starting a count and being "in progress" are the same moment, nothing meaningful happens
 * between them (the same kind of collapse Phase B made for "Submitted"/"Under Review"). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stocktakes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained('inventory_stores')->restrictOnDelete();
            $table->enum('status', ['in_progress', 'completed'])->default('in_progress');
            $table->foreignId('started_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('started_at');
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stocktakes');
    }
};
