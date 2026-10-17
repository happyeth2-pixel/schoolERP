<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One medical profile per student -- academic expansion, Phase 4. Deliberately does NOT use the
 * `Audited` trait: that trait logs a full field-level before/after diff into `audit_log`, which anyone
 * holding `view-audit-log` (the broad oversight permission) can read -- a strictly BIGGER audience than
 * `medical-records-view`/`manage`. Logging the actual allergy/condition/medication text into the audit
 * trail would silently defeat the whole point of this record being access-restricted at all. Instead,
 * `StudentMedicalRecordController` writes its own minimal `AuditLog::record()` entry on every save (who
 * touched this student's medical record, when) with NO field content in `before`/`after` -- a "this was
 * changed" trail, not a "here is what changed" one.
 */
class StudentMedicalRecord extends Model
{
    protected $fillable = ['student_id', 'blood_type', 'allergies', 'conditions', 'medications', 'physician_name', 'physician_phone', 'notes', 'updated_by'];

    protected function casts(): array
    {
        return ['physician_phone' => \App\Casts\Phone::class];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** Used by AuditLog::record() as the log entry's subject_label -- the student's identity, never the
     * medical content itself. */
    public function auditLabel(): string
    {
        return $this->student?->auditLabel() ?? "Student #{$this->student_id}";
    }
}
