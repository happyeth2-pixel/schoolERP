<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Academic expansion, Phase 3: the school calendar -- holidays, exam periods, meetings, activities and
 * deadlines, each visible to everyone/parents-only/staff-only (the exact same 3-bucket audience
 * `Announcement` already uses, confirmed with the user rather than a finer per-role picker) and optionally
 * scoped to one branch (NULL = every branch, same convention as `announcements.branch_id`). Becomes visible
 * and notifies its audience the moment it is created -- there is no separate "publish later" concept here,
 * unlike Announcement's `publish_on`: the event's own `start_date` already says WHEN it happens, which is a
 * different question from when people should be told about it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->cascadeOnDelete();   // NULL = every branch
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('event_type', ['holiday', 'exam', 'meeting', 'activity', 'deadline', 'other'])->default('other');
            $table->enum('audience', ['everyone', 'parents', 'staff'])->default('everyone');
            $table->date('start_date');
            $table->date('end_date')->nullable();   // NULL = a single-day event (the same day as start_date)
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['school_id', 'start_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_events');
    }
};
