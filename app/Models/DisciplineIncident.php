<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One behavioral incident for a student -- academic expansion, Phase 7. Deliberately does NOT use the
 * `Audited` trait, for the exact same reason as `StudentMedicalRecord`: that trait would log a full
 * field-level before/after diff into the audit log, but `view-audit-log` is a BROADER permission than
 * `discipline-view`/`manage` -- anyone who can merely see the audit log would otherwise be able to read a
 * child's incident description and action taken straight out of it. `DisciplineIncidentController` writes
 * its own minimal `AuditLog::record()` entry instead, with no field content in before/after.
 */
class DisciplineIncident extends Model
{
    public const CATEGORIES = ['tardiness', 'attendance', 'disrespect', 'bullying', 'fighting', 'cheating', 'vandalism', 'dress_code', 'other'];

    public const ACTIONS = ['none', 'verbal_warning', 'written_warning', 'detention', 'parent_contacted', 'suspension', 'expulsion', 'other'];

    public const STATUSES = ['open', 'under_review', 'resolved'];

    protected $fillable = [
        'student_id', 'category', 'description', 'incident_date', 'action_taken', 'status',
        'reported_by', 'resolved_by', 'resolved_at', 'attachment_path', 'attachment_original_name',
    ];

    protected function casts(): array
    {
        return [
            'incident_date' => 'date',
            'resolved_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /** Used by AuditLog::record() as the log entry's subject_label -- the student's identity, never the
     * incident content itself. */
    public function auditLabel(): string
    {
        return $this->student?->auditLabel() ?? "Student #{$this->student_id}";
    }
}
