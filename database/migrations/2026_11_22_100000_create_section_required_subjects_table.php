<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ranking rework: which subjects actually count toward a section's class ranking is now something the
 * homeroom (class) teacher or an admin explicitly picks for that section, instead of being silently
 * inferred from "whatever has a published result batch so far" (ReportCardCalculator's old R2). An empty
 * set here means "not configured yet" -- ReportCardCalculator falls back to the old auto-detect behaviour
 * so existing sections/report cards don't change until someone actually sets a list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('section_required_subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('section_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['section_id', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('section_required_subjects');
    }
};
