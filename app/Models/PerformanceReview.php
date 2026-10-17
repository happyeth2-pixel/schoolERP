<?php

namespace App\Models;

use App\Exceptions\DeletionBlockedException;
use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One employee's review for one `PerformanceReviewCycle` -- HR Module Phase E. `draft` can be freely edited
 * or deleted; once `finalized` it is immutable, the same "immutable once real" rule `EmploymentContract`/
 * `LeaveRequest` already use. A reviewer can never author or finalize a review of their OWN employee record
 * -- the same self-review-ban spirit reused across Procurement/Staff Attendance/Leave, enforced in the
 * controller since (unlike those) there is no separate requester-vs-reviewer split to check here.
 */
class PerformanceReview extends Model
{
    use Audited;

    public const STATUSES = ['draft', 'finalized'];

    public const RATINGS = [1, 2, 3, 4, 5];

    protected $fillable = ['employee_id', 'cycle_id', 'rating', 'comments', 'status', 'reviewed_by', 'finalized_at'];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'finalized_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(PerformanceReviewCycle::class, 'cycle_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public static function booted(): void
    {
        static::deleting(function (self $review) {
            if ($review->status === 'finalized') {
                throw new DeletionBlockedException('A finalized performance review cannot be removed.');
            }
        });
    }

    public function auditLabel(): string
    {
        return "{$this->employee->full_name} -- {$this->cycle->name}";
    }

    public function auditAction(array $changed): string
    {
        if (in_array('status', $changed, true) && $this->status === 'finalized') {
            return 'performance_review.finalized';
        }

        return 'performance_review.updated';
    }
}
