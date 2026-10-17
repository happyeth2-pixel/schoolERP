<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Academic expansion, Phase 7: student discipline -- incident/category/action/status/attachments, "under
 * permission protection" (the brief's own words). Deliberately permanent once created (no destroy route at
 * all): a behavioral record should never be quietly deletable, the same append-only philosophy Payment/
 * Invoice/AuditLog already follow -- a mistake is corrected by editing its category/description/status, never
 * by erasing that it existed. Scope (school/branch) is derived via the related `Student`, never duplicated
 * onto this table -- the same choice `student_medical_records` already made.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discipline_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->enum('category', ['tardiness', 'attendance', 'disrespect', 'bullying', 'fighting', 'cheating', 'vandalism', 'dress_code', 'other']);
            $table->text('description');
            $table->date('incident_date');
            $table->enum('action_taken', ['none', 'verbal_warning', 'written_warning', 'detention', 'parent_contacted', 'suspension', 'expulsion', 'other'])->default('none');
            $table->enum('status', ['open', 'under_review', 'resolved'])->default('open');
            $table->foreignId('reported_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->string('attachment_path')->nullable();
            $table->string('attachment_original_name')->nullable();
            $table->timestamps();

            $table->index(['student_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discipline_incidents');
    }
};
