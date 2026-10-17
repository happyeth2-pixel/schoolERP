<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A department/teacher's request to draw stock from a store. Never hard-deleted once submitted -- see
 * Requisition::booted(); a draft (never submitted) can still be discarded, since it isn't a real request yet. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('requisitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained('inventory_stores')->restrictOnDelete();
            $table->foreignId('requester_id')->constrained('users')->restrictOnDelete();
            $table->string('number')->unique();
            $table->string('department')->nullable();
            $table->string('purpose');
            $table->enum('priority', ['low', 'normal', 'urgent'])->default('normal');
            $table->enum('status', ['draft', 'submitted', 'approved', 'rejected', 'partially_issued', 'fully_issued', 'completed'])->default('draft');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('rejection_reason')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('requisitions');
    }
};
