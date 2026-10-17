<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Not every child fits one family shape. Only the first name is required; the
 * second/third/mother names are optional. And a guardian is just a
 * relationship (father, mother, grandparent, aunt, ...) -- the list is no longer
 * a 3-value database enum, so it can grow without a migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->string('father_name')->nullable()->change();
        });

        Schema::table('parent_student', function (Blueprint $table) {
            $table->string('relationship', 30)->change();
        });
    }

    public function down(): void
    {
        // Not reversible without inventing data for students that have no second name.
    }
};
