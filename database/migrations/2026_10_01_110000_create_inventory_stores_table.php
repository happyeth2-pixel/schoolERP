<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Where stock physically sits: Main Store, Laboratory Store, ICT Store, a per-branch store... `branch_id`
 * null means shared by every branch of the school, exactly like PaymentAccount and Announcement. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_stores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_stores');
    }
};
