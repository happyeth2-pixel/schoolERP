<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every time a mark batch is published, a row records who, when, and exactly which scores were
 * published (a snapshot). Reopening closes that row with who/when/why; publishing again opens a
 * new version. So what parents saw in each published version stays traceable even after the
 * batch is reopened and its marks corrected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mark_batch_publications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mark_batch_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at');
            $table->json('marks_snapshot');
            $table->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reopened_at')->nullable();
            $table->text('reopen_reason')->nullable();
            $table->timestamps();

            $table->unique(['mark_batch_id', 'version']);
        });

        // Batches that are ALREADY published get a version 1 now, so the history is complete from the start.
        foreach (DB::table('mark_batches')->where('status', 'published')->get() as $batch) {
            $marks = DB::table('marks')->where('mark_batch_id', $batch->id)->get(['enrollment_id', 'score', 'max_score']);
            DB::table('mark_batch_publications')->insert([
                'mark_batch_id' => $batch->id,
                'version' => 1,
                'published_by' => null,
                'published_at' => $batch->published_at ?? now(),
                'marks_snapshot' => json_encode($marks->map(fn ($m) => ['enrollment_id' => $m->enrollment_id, 'score' => $m->score, 'max_score' => $m->max_score])->all()),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mark_batch_publications');
    }
};
