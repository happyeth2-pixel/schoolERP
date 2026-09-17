<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Opt-in extras (tutoring, transportation, ...) -- unlike
     * FeeStructure these are never applied to a whole grade
     * automatically; a student is only billed for one if enrolled in
     * it via student_optional_service.
     */
    public function up(): void
    {
        Schema::create('optional_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('amount', 10, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('optional_services');
    }
};
