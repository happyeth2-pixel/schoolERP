<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A REQUEST to change an already-recorded `StaffAttendance` day -- never a direct edit. The brief asks for
 * "manual correction" AND "correction approval" as distinct capabilities (unlike student attendance, which
 * is a plain re-save with no approval gate -- staff attendance is more sensitive, feeding overtime/leave
 * records later phases build on, so it gets real two-person control here). The same self-review ban already
 * used for Procurement's "a requester can never approve their own request" applies: whoever requests a
 * correction can never be the one who approves or rejects it, enforced server-side regardless of permissions
 * held.
 */
class StaffAttendanceCorrection extends Model
{
    use Audited;

    public const STATUSES = ['pending', 'approved', 'rejected'];

    protected $fillable = [
        'staff_attendance_id', 'new_status', 'new_check_in', 'new_check_out', 'new_overtime_minutes',
        'reason', 'status', 'requested_by', 'reviewed_by', 'reviewed_at',
    ];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    public function staffAttendance(): BelongsTo
    {
        return $this->belongsTo(StaffAttendance::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function auditLabel(): string
    {
        return "Correction request for {$this->staffAttendance->auditLabel()}";
    }

    public function auditAction(array $changed): string
    {
        return in_array('status', $changed, true) ? 'staff_attendance_correction.'.$this->status : 'staff_attendance_correction.updated';
    }

    public function auditReason(array $changed): ?string
    {
        return $this->reason;
    }
}
