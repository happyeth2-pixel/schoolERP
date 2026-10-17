<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeachingAssignment extends Model
{
    use Audited;

    use HasFactory;

    protected $fillable = ['teacher_id', 'section_id', 'subject_id'];

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function auditAction(array $changed): string
    {
        return 'teaching_assignment.updated';
    }

    public function auditLabel(): string
    {
        return trim(($this->subject?->name ?? 'Subject').' — '.($this->section?->grade?->name ?? '').' '.($this->section?->name ?? '').' — '.($this->teacher?->name ?? 'teacher #'.$this->teacher_id));
    }
}
