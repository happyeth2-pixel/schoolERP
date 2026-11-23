<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The public homepage's "top students" block -- the principal/owner picks which ranked students (already
 * computed by ReportCardCalculator) to put on the public site, and can take any one back down without
 * losing the record of it having been posted (soft, via `published_at`, not a delete -- same "never
 * silently remove" discipline as the rest of the app's public-content models: SiteAnnouncement,
 * GalleryImage). A row is a deliberate editorial choice by a named person, not a live query re-run on
 * every page load -- marks can be corrected/reopened after publishing without the homepage silently
 * changing underneath a photo that's already been shared.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('top_student_highlights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('section_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('rank');
            $table->decimal('overall_average', 5, 2)->nullable();
            $table->foreignId('posted_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['school_id', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('top_student_highlights');
    }
};
