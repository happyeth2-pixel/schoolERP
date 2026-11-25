<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which subjects a SECTION actually teaches -- 1A and 1B are not guaranteed to share a curriculum, so this
 * is picked per section (the same grain section_required_subjects already uses for ranking), not per
 * grade. It is deliberately a separate pivot from section_required_subjects: "what this class teaches"
 * and "what counts toward its ranking" are different questions. An empty set here means "not configured
 * yet" -- the Teaching Assignments "give a subject to a teacher" picker falls back to every subject the
 * school has, so every existing section keeps working unchanged until a principal or homeroom teacher
 * actually curates one down.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('section_subjects', function (Blueprint $table) {
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
        Schema::dropIfExists('section_subjects');
    }
};
