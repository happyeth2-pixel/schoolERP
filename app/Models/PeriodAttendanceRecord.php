<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\NeverHardDeleted;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Attendance for ONE class period -- academic expansion, Phase 2. Deliberately separate from the EXISTING
 * `AttendanceRecord` (one row per student per DAY, homeroom-only): this is one row per student per
 * `TimetableSlot` occurrence per date, so a student can be marked separately in each subject's own period.
 */
class PeriodAttendanceRecord extends Model
{
    use Audited;
    use NeverHardDeleted;

    protected $fillable = ['timetable_slot_id', 'enrollment_id', 'date', 'status', 'marked_by', 'remarks'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }

    public function timetableSlot(): BelongsTo
    {
        return $this->belongsTo(TimetableSlot::class);
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function markedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marked_by');
    }

    /** One row per student per PERIOD per day -- auditing every creation would spam the log far worse than
     * even daily attendance already deliberately avoids; only a later change to an existing mark is worth it. */
    public function auditsCreation(): bool
    {
        return false;
    }

    public function auditAction(array $changed): string
    {
        return 'period_attendance.status_changed';
    }

    public function auditLabel(): string
    {
        return trim(($this->enrollment?->student?->fullName() ?? 'Enrollment #'.$this->enrollment_id).' -- '.($this->timetableSlot?->subject?->name ?? '').' on '.($this->date?->toDateString() ?? ''));
    }
}
