<?php

namespace App\Models;

use App\Exceptions\DeletionBlockedException;
use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A department or teacher's request to draw stock from a store. "Submitted" and "Under Review" from the
 * brief are one status here (`submitted`) -- nothing happens between them that needs its own state. Once
 * fully issued there is nothing left to confirm, so it becomes `completed` at that same moment (see
 * markIssued()) rather than needing a separate closing action.
 */
class Requisition extends Model
{
    use Audited;
    use SoftDeletes;

    public const STATUSES = ['draft', 'submitted', 'approved', 'rejected', 'partially_issued', 'fully_issued', 'completed'];

    public const PRIORITIES = ['low', 'normal', 'urgent'];

    protected $fillable = [
        'school_id', 'branch_id', 'store_id', 'requester_id', 'number', 'department', 'purpose', 'priority',
        'status', 'reviewed_by', 'reviewed_at', 'rejection_reason', 'completed_at',
    ];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::deleting(function (Requisition $r) {
            if ($r->status !== 'draft') {
                throw new DeletionBlockedException('Only a draft (never submitted) requisition can be removed -- change its status instead.');
            }
        });
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(InventoryStore::class, 'store_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(RequisitionItem::class);
    }

    public function auditLabel(): string
    {
        return "Requisition {$this->number}";
    }

    public function auditAction(array $changed): string
    {
        return 'requisition.'.(in_array('status', $changed, true) ? 'status_changed' : 'updated');
    }

    public function auditReason(array $changed): ?string
    {
        return $this->status === 'rejected' ? $this->rejection_reason : null;
    }

    /** Recomputes status from the line items' own issued-vs-approved quantities. Call after every issue.
     * Fully issued has nothing left to confirm, so it becomes `completed` in the same step. */
    public function refreshIssueStatus(): void
    {
        $items = $this->items()->get();
        $totalApproved = $items->sum('quantity_approved');
        $totalIssued = $items->sum('quantity_issued');

        if ($totalIssued <= 0) {
            $this->status = 'approved';
        } elseif ($totalIssued < $totalApproved) {
            $this->status = 'partially_issued';
        } else {
            $this->status = 'completed';
            $this->completed_at = now();
        }

        $this->save();
    }
}
