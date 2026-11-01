<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Private, per-student messaging between a student's current homeroom (class) teacher -- or any owner/
 * branch_admin -- and that student's guardians. Deliberately permanent and never editable (no update/destroy
 * route at all), the same append-only philosophy DisciplineIncident/Payment/AuditLog already follow: a school
 * talking to a child's parent is exactly the kind of record that must never be quietly alterable after the
 * fact, and the owner/auditor can always read it for oversight (see ParentMessage::canView()).
 *
 * `section_id` is set only for a homeroom teacher's one-tap "send to every parent in my class" (e.g. "today's
 * homework"): one row per student in the section, all sharing the same `broadcast_id` so the UI can group them
 * back into a single sent message. An ordinary one-to-one message leaves both columns null. Scope (school/
 * branch) is derived via the related Student, never duplicated onto this table -- the same choice
 * `student_medical_records`/`discipline_incidents` already made.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parent_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->restrictOnDelete();
            $table->text('body');
            $table->foreignId('section_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('broadcast_id')->nullable();
            $table->timestamps();

            $table->index(['student_id', 'created_at']);
            $table->index('broadcast_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parent_messages');
    }
};
