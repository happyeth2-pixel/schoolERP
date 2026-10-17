<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            // exclude = a missing score is left out and the remaining weights are
            //           re-normalized; zero = a missing score counts as 0%.
            $table->enum('missing_mark_policy', ['exclude', 'zero'])->default('exclude')->after('calendar_preference');
        });
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn('missing_mark_policy');
        });
    }
};
