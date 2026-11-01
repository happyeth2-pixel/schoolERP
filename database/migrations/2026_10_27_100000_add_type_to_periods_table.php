<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A break or lunch period is a real, distinct kind of period -- not just a teaching period someone happened
 * to name "Break". Marking it as its own `type` means the timetable itself can refuse to let anyone assign a
 * subject/teacher to a break or lunch period (TimetableController::resolveScoped()), rather than relying on
 * everyone remembering not to. Existing rows default to 'teaching', the only type that has ever existed until
 * now, so nothing already scheduled changes meaning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('periods', function (Blueprint $table) {
            $table->enum('type', ['teaching', 'break', 'lunch'])->default('teaching')->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('periods', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
