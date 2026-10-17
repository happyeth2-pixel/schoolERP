<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Academic expansion, Phase 4: one medical profile per student (allergies, chronic conditions, current
 * medications, blood type, treating physician, general notes) -- a single, always-upserted record, not a
 * timestamped log of separate incidents (no requirement for that was asked for, and it would be a far bigger
 * build for a "foundation" phase). Deliberately no file attachment here either, unlike EmploymentContract's
 * document or Leave's supporting document -- the brief never asked for one, and a plain text field already
 * covers "allergic to penicillin" perfectly well. `student_id` is unique: there is exactly one record per
 * student, created the first time anyone saves one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_medical_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('blood_type')->nullable();
            $table->text('allergies')->nullable();
            $table->text('conditions')->nullable();
            $table->text('medications')->nullable();
            $table->string('physician_name')->nullable();
            $table->string('physician_phone')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_medical_records');
    }
};
