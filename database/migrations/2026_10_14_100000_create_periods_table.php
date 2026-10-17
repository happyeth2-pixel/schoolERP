<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Academic expansion, Phase 1 (Timetable): a school's own bell-schedule catalog -- "Period 1" etc, each
 * with its own default start/end time. A `TimetableSlot` references a period rather than storing its own
 * start/end time, so every "Period 3" slot across the whole school always means the same clock time,
 * never duplicated or allowed to drift. School-wide (not per-branch): Ethiopian schools with several
 * branches overwhelmingly share one daily bell schedule; a branch-specific override was not asked for. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('period_number');
            $table->string('name')->nullable();
            $table->time('start_time');
            $table->time('end_time');
            $table->timestamps();

            $table->unique(['school_id', 'period_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('periods');
    }
};
