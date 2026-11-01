<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An after-school programme (category 'asp') is the only optional service that ever needs a schedule -- a
 * transport route or a generic "other" extra has nothing to put here, so these stay nullable for every
 * service that isn't one. When they vary (chess club meets a different teacher/room than swimming), they are
 * just a different OptionalService row, the same as two different transport routes already are.
 *  - period_id: which of the school's own bell-schedule periods it meets in (must be a 'teaching' period --
 *    see Period::TEACHING_TYPE and OptionalServiceController's own validation).
 *  - teacher_id: who runs it, so they get a roster of only the students actually enrolled
 *    (OptionalServiceController::myPrograms()), the same "see your own, not everyone's" shape Timetable's
 *    own TimetableChanged notification already established.
 *  - days_of_week: which weekdays it meets (1=Monday..7=Sunday, ISO-8601, the same convention
 *    TimetableSlot::day_of_week already uses) -- a JSON array, since one programme can meet more than one day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('optional_services', function (Blueprint $table) {
            $table->foreignId('period_id')->nullable()->after('category')->constrained()->nullOnDelete();
            $table->foreignId('teacher_id')->nullable()->after('period_id')->constrained('users')->nullOnDelete();
            $table->json('days_of_week')->nullable()->after('teacher_id');
        });
    }

    public function down(): void
    {
        Schema::table('optional_services', function (Blueprint $table) {
            $table->dropConstrainedForeignId('period_id');
            $table->dropConstrainedForeignId('teacher_id');
            $table->dropColumn('days_of_week');
        });
    }
};
