<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\GuardsDeletion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One weekly teaching slot -- academic expansion, Phase 1 (Timetable). A wholly new concept layered on top
 * of the EXISTING `TeachingAssignment` (teacher x section x subject, no day/time at all); this is what
 * finally answers "when." `day_of_week` is 1-7 (Monday=1 .. Sunday=7). `override_reason` is set only when
 * an authorized holder of `timetable-override` saved this slot despite a detected teacher/room/section
 * conflict -- see `App\Services\TimetableConflicts`.
 */
class TimetableSlot extends Model
{
    use Audited;
    use GuardsDeletion;

    public const DAYS = [1 => 'monday', 2 => 'tuesday', 3 => 'wednesday', 4 => 'thursday', 5 => 'friday', 6 => 'saturday', 7 => 'sunday'];

    protected $fillable = [
        'school_id', 'branch_id', 'term_id', 'section_id', 'subject_id', 'teacher_id',
        'room_id', 'period_id', 'day_of_week', 'override_reason', 'created_by',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
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

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function periodAttendanceRecords(): HasMany
    {
        return $this->hasMany(PeriodAttendanceRecord::class);
    }

    /** Academic expansion, Phase 2: once real period attendance has been taken against this slot, it is
     * part of the historical record and can no longer simply be deleted out from under it. */
    public function deletionBlockers(): array
    {
        $blockers = [];
        if (($count = $this->periodAttendanceRecords()->count()) > 0) {
            $blockers[] = "{$count} period attendance record(s)";
        }

        return $blockers;
    }

    public function auditLabel(): string
    {
        return "{$this->subject->name} -- {$this->section->auditLabel()} -- ".self::DAYS[$this->day_of_week];
    }

    /**
     * The trait's own `created` hook always writes a plain "X.created" entry and never consults
     * auditAction() -- so a slot saved with a conflict override at CREATION time (not just on later
     * updates) needs its own entry written here instead, via the `created` listener below.
     */
    public function auditsCreation(): bool
    {
        return $this->override_reason === null;
    }

    protected static function booted(): void
    {
        static::created(function (self $slot) {
            if ($slot->override_reason !== null) {
                $slot->writeAudit('timetable_slot.conflict_overridden', null, $slot->auditSnapshot(array_keys($slot->getAttributes())), $slot->override_reason);
            }
        });
    }

    public function auditAction(array $changed): string
    {
        return in_array('override_reason', $changed, true) && $this->override_reason !== null
            ? 'timetable_slot.conflict_overridden'
            : $this->auditName().'.updated';
    }

    public function auditReason(array $changed): ?string
    {
        return in_array('override_reason', $changed, true) ? $this->override_reason : null;
    }
}
