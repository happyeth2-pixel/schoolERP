<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A record of every report card PDF that was issued: the average, the rank and
 * the exact calculation basis printed on it, so a rank can always be explained
 * later, even after more results are published and live ranks move.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_card_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('section_id')->constrained()->cascadeOnDelete();
            $table->decimal('overall_average', 5, 2)->nullable();
            $table->unsignedInteger('rank')->nullable();
            $table->unsignedInteger('ranked_count');
            $table->unsignedInteger('class_size');
            $table->unsignedSmallInteger('rules_version');
            $table->string('missing_mark_policy', 20);
            $table->json('basis');
            $table->json('subjects');
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_card_snapshots');
    }
};
