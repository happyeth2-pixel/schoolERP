<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Concerns\NeverHardDeleted;
use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Enrollment extends Model
{
    use Audited;

    use NeverHardDeleted;

    use HasFactory;

    protected $fillable = ['student_id', 'section_id', 'enrolled_on', 'status'];

    protected function casts(): array
    {
        return [
            'enrolled_on' => 'date',
        ];
    }

    /**
     * The one definition of "this enrollment counts right now": the
     * enrollment itself is active AND the student's lifecycle status
     * still allows being enrolled. Every roster, invoice run, mark
     * entry and dashboard count goes through this scope so they can
     * never disagree with each other.
     */
    public function scopeActive($query)
    {
        return $query->where('enrollments.status', 'active')
            ->whereHas('student', fn ($s) => $s->whereIn('status', Student::ENROLLED_STATUSES));
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    public function periodAttendanceRecords(): HasMany
    {
        return $this->hasMany(PeriodAttendanceRecord::class);
    }

    public function marks(): HasMany
    {
        return $this->hasMany(Mark::class);
    }

    public function auditLabel(): string
    {
        return trim(($this->student?->fullName() ?? 'Student #'.$this->student_id).' in '.($this->section?->grade?->name ?? '').' '.($this->section?->name ?? 'section #'.$this->section_id));
    }

    public function auditAction(array $changed): string
    {
        return in_array('status', $changed, true) ? 'enrollment.status_changed' : 'enrollment.updated';
    }
}
