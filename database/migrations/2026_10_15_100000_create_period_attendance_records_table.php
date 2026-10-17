<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Academic expansion, Phase 2: attendance for a single class period, not the whole day -- a wholly separate
 * record from the EXISTING `attendance_records` (one row per student per DAY, homeroom-only). Keyed to a
 * `timetable_slot_id` (which already carries section/subject/teacher/period/day_of_week) plus the actual
 * calendar `date` that occurrence falls on, so "Grade 3 A's Mathematics, Monday Period 2, on 2026-10-13" is
 * unambiguous. Four statuses per the brief's own wording (no `sick`, unlike daily attendance -- that
 * distinction belongs to the homeroom record, not every single class).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('period_attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('timetable_slot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enrollment_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->enum('status', ['present', 'absent', 'late', 'excused']);
            $table->foreignId('marked_by')->constrained('users')->cascadeOnDelete();
            $table->string('remarks')->nullable();
            $table->timestamps();

            $table->unique(['enrollment_id', 'timetable_slot_id', 'date'], 'period_attendance_one_per_student_slot_day');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('period_attendance_records');
    }
};
