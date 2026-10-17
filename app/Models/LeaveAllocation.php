<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** How many days of a given LeaveType an Employee has for a given calendar year. `allocated_days` is the
 * only thing stored here -- used/remaining are NEVER cached (the same never-cache-a-total discipline this
 * whole app follows everywhere else): `usedDays()`/`balance()` sum live from approved `LeaveRequest` rows
 * every time, so they can never drift out of sync with the actual approval history. */
class LeaveAllocation extends Model
{
    use Audited;

    protected $fillable = ['employee_id', 'leave_type_id', 'year', 'allocated_days', 'notes', 'created_by'];

    protected function casts(): array
    {
        return ['allocated_days' => 'decimal:1'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Sum of approved requests of this type that START within this allocation's year -- never cached. */
    public function usedDays(): float
    {
        return (float) LeaveRequest::where('employee_id', $this->employee_id)
            ->where('leave_type_id', $this->leave_type_id)
            ->where('status', 'approved')
            ->whereYear('start_date', $this->year)
            ->sum('days_count');
    }

    public function balance(): float
    {
        return round((float) $this->allocated_days - $this->usedDays(), 1);
    }

    public function auditLabel(): string
    {
        return "{$this->leaveType->name} allocation for {$this->employee->full_name} ({$this->year})";
    }
}
