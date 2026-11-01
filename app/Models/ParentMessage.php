<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * One private message between a student's current homeroom teacher (or a branch admin/owner) and that
 * student's guardians -- see create_parent_messages_table for why this deliberately does NOT use the
 * `Audited` trait (the same reason as `DisciplineIncident`/`StudentMedicalRecord`): `view-audit-log` is a
 * broader permission than `parent-messages`, so a message's actual text must never land in the audit log.
 * `ParentMessageController` writes its own minimal `AuditLog::record()` entry with no body content.
 *
 * Never updated or deleted once sent -- there is no update/destroy route at all.
 */
class ParentMessage extends Model
{
    protected $fillable = ['student_id', 'sender_id', 'body', 'section_id', 'broadcast_id'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }
}
