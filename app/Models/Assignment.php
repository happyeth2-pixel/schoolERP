<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Notifications\AssignmentDueSoon;
use App\Notifications\AssignmentPublished;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Notification;

/**
 * A teacher-authored homework/assignment item for one section+subject -- "a simple foundation,"
 * the brief's own words, not a submission/grading system. Ordinary content (not medical/discipline-grade
 * sensitive), so the normal `Audited` trait applies here, unlike StudentMedicalRecord/DisciplineIncident.
 */
class Assignment extends Model
{
    use Audited;

    public const STATUSES = ['draft', 'published'];

    protected $fillable = [
        'section_id', 'subject_id', 'teacher_id', 'title', 'description', 'due_date',
        'attachment_path', 'attachment_original_name', 'status', 'published_at', 'due_soon_notified_at',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'published_at' => 'datetime',
            'due_soon_notified_at' => 'datetime',
        ];
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published');
    }

    public function auditLabel(): string
    {
        return trim(($this->subject?->name ?? 'Subject').' — '.($this->section?->auditLabel() ?? '').' — '.$this->title);
    }

    /** draft -> published, exactly once: notifies the section's guardians the moment it becomes visible. */
    public function publish(): void
    {
        if ($this->status === 'published') {
            return;
        }

        $this->forceFill(['status' => 'published', 'published_at' => now()])->save();
        $this->notifyGuardians('published');
    }

    /** Called by the due-soon reminder command only; guarded by due_soon_notified_at so it fires once. */
    public function notifyDueSoon(): void
    {
        if ($this->due_soon_notified_at !== null) {
            return;
        }

        $this->notifyGuardians('due_soon');
        $this->forceFill(['due_soon_notified_at' => now()])->save();
    }

    /**
     * One notification per enrolled student, to that student's own guardians (the same per-student shape
     * `StudentMarkedAbsent` already uses) -- never a single broadcast to the whole class's guardians at
     * once, so each notification can carry the one child it is actually about for correct portal routing.
     */
    private function notifyGuardians(string $type): void
    {
        $enrollments = $this->section->enrollments()->active()->with('student.guardians')->get();

        foreach ($enrollments as $enrollment) {
            $guardians = $enrollment->student->guardians;
            if ($guardians->isEmpty()) {
                continue;
            }

            $notification = $type === 'published'
                ? new AssignmentPublished($this, $enrollment->student)
                : new AssignmentDueSoon($this, $enrollment->student);

            Notification::send($guardians, $notification);
        }
    }
}
