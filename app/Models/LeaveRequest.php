<?php

namespace App\Models;

use App\Exceptions\DeletionBlockedException;
use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Draft -> Submitted -> Approved/Rejected -> (the employee's LeaveAllocation balance reflects it, live,
 * the moment it's approved -- nothing is ever cached, so "balance updated" is really just "now included in
 * the live sum"). A draft is a private work-in-progress and can be freely edited or removed; the moment it
 * is submitted it enters the real workflow and is kept forever, same "immutable once real" rule
 * EmploymentContract/PurchaseOrder/Quotation already follow. Approving/rejecting follows the exact
 * "a requester can never review their own request" rule already established for Procurement and staff
 * attendance corrections.
 */
class LeaveRequest extends Model
{
    use Audited;

    public const STATUSES = ['draft', 'submitted', 'approved', 'rejected'];

    protected $fillable = [
        'employee_id', 'leave_type_id', 'start_date', 'end_date', 'days_count', 'reason',
        'document_path', 'document_original_name', 'status', 'requested_by',
        'reviewed_by', 'reviewed_at', 'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'days_count' => 'decimal:1',
            'reviewed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (LeaveRequest $request) {
            if ($request->status !== 'draft') {
                throw new DeletionBlockedException('Only a draft leave request can be removed -- once submitted it stays in the employee\'s leave history.');
            }
        });
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** Inclusive calendar-day count between start and end -- kept deliberately simple (no working-day
     * calendar/holiday awareness), matching the brief's own plain "3 days annual leave" example. */
    public static function dayCount(string $startDate, string $endDate): float
    {
        return \Carbon\Carbon::parse($startDate)->diffInDays(\Carbon\Carbon::parse($endDate)) + 1;
    }

    /** Whether a [start,end] range overlaps any of this employee's OTHER submitted/approved requests --
     * draft and rejected requests never block (nothing live to collide with). */
    public static function overlapsExisting(int $employeeId, string $startDate, string $endDate, ?int $excludingId = null): bool
    {
        return static::where('employee_id', $employeeId)
            ->whereIn('status', ['submitted', 'approved'])
            ->when($excludingId, fn ($q) => $q->where('id', '!=', $excludingId))
            ->where('start_date', '<=', $endDate)
            ->where('end_date', '>=', $startDate)
            ->exists();
    }

    public function auditLabel(): string
    {
        return "{$this->leaveType->name} request for {$this->employee->full_name} ({$this->start_date->toDateString()} to {$this->end_date->toDateString()})";
    }

    public function auditAction(array $changed): string
    {
        return in_array('status', $changed, true) ? 'leave_request.'.$this->status : 'leave_request.updated';
    }

    public function auditExcluded(): array
    {
        return ['document_path', 'document_original_name'];
    }
}
