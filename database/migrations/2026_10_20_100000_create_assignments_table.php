<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Academic expansion, Phase 8: "a simple foundation for teacher assignments" (the brief's own words) --
 * subject/class/teacher/title/description/due date/attachment/status, nothing more. Scoped purely through
 * `section_id`, the same lean approach `mark_batches` already uses (no denormalized school_id/branch_id --
 * TimetableSlot's own direct columns exist for a different reason, fast standalone conflict queries, that
 * doesn't apply here). `teacher_id` is always derived from the CURRENT `teaching_assignments` row for this
 * section+subject, never picked freely -- the same "re-check live, never trust a stored id forever"
 * principle `MarkBatchPolicy::enterMarks` already established.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('section_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('teacher_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->date('due_date');
            $table->string('attachment_path')->nullable();
            $table->string('attachment_original_name')->nullable();
            $table->enum('status', ['draft', 'published'])->default('draft');
            $table->timestamp('published_at')->nullable();
            // guards the due-soon reminder so it fires exactly once per assignment, never on every cron tick
            $table->timestamp('due_soon_notified_at')->nullable();
            $table->timestamps();

            $table->index(['section_id', 'status']);
            $table->index('due_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignments');
    }
};
