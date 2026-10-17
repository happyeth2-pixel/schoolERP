<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One employee's attendance for one day -- deliberately a SEPARATE table from the student `attendance_records`
 * (keyed on `enrollment_id`), never confused with it. Recorded by HR/admin (confirmed via AskUserQuestion:
 * no self-service check-in in this phase), never by the employee themselves. The FIRST entry for a given
 * (employee, date) is written directly; changing it afterward goes through `StaffAttendanceCorrection`
 * instead of a plain update -- see that model's own docblock for why.
 */
class StaffAttendance extends Model
{
    use Audited;

    public const STATUSES = ['present', 'late', 'absent', 'early_departure'];

    protected $fillable = ['employee_id', 'date', 'status', 'check_in', 'check_out', 'overtime_minutes', 'notes', 'recorded_by'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(StaffAttendanceCorrection::class)->orderByDesc('created_at');
    }

    public function auditLabel(): string
    {
        return "{$this->employee->full_name}'s attendance on {$this->date->toDateString()}";
    }
}
