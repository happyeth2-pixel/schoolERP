<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Academic expansion, Phase 1: one weekly teaching slot -- a wholly new concept layered on top of the
 * EXISTING `teaching_assignments` table, which has no day/time component at all. `day_of_week` is 1-7
 * (Monday=1 .. Sunday=7, ISO-8601 convention); a school simply never creates slots on days it doesn't use.
 * `term_id` (not academic_year_id) is the scheduling scope -- the same scope Exam already uses, and avoids
 * the ambiguity of a nullable "whole year" slot conflicting or not with a term-specific one.
 *
 * Conflict rules (teacher/room/section double-booking) are enforced in application logic
 * (`App\Services\TimetableConflicts`), never a DB constraint -- an authorized override must be allowed to
 * proceed with a recorded reason, which a unique index could never express.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timetable_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('term_id')->constrained()->cascadeOnDelete();
            $table->foreignId('section_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->foreignId('teacher_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('period_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('day_of_week');
            $table->text('override_reason')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['term_id', 'day_of_week', 'period_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timetable_slots');
    }
};
