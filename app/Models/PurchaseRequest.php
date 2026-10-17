<?php

namespace App\Models;

use App\Exceptions\DeletionBlockedException;
use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A staff request to buy something -- inventory or a service -- before any supplier is chosen or a
 * Purchase Order exists. "Submitted" and "Under Review" from the brief are one status (`submitted`),
 * exactly the same collapse Requisition already made -- nothing happens between them that needs its own
 * state. Estimated totals are never cached here: always summed live from the items, the same rule Invoice
 * uses for its own totals.
 */
class PurchaseRequest extends Model
{
    use Audited;
    use SoftDeletes;

    public const STATUSES = ['draft', 'submitted', 'approved', 'rejected'];

    public const PRIORITIES = ['low', 'normal', 'urgent'];

    protected $fillable = [
        'school_id', 'branch_id', 'requester_id', 'number', 'department', 'purpose', 'priority',
        'status', 'reviewed_by', 'reviewed_at', 'rejection_reason', 'notes', 'attachments',
    ];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime', 'attachments' => 'array'];
    }

    protected static function booted(): void
    {
        static::deleting(function (PurchaseRequest $r) {
            if ($r->status !== 'draft') {
                throw new DeletionBlockedException('Only a draft (never submitted) purchase request can be removed -- change its status instead.');
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
        return $this->hasMany(PurchaseRequestItem::class);
    }

    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class);
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    /** Never cached -- summed live from the items, same rule Invoice uses for its own totals. */
    public function estimatedTotal(): float
    {
        return (float) $this->items->sum('estimated_total');
    }

    /** Whether THIS request needs the large-purchase tier, per the school's own configured threshold
     * (null = no two-tier distinction is configured at all -- everything just needs purchase-approve). */
    public function needsLargeApproval(): bool
    {
        $threshold = $this->school->purchase_approval_threshold;

        return $threshold !== null && $this->estimatedTotal() > (float) $threshold;
    }

    /** Every line fully received across however many purchase orders and goods receipts it took -- the
     * closing state of the whole request/order/receipt chain. Never cached; a request with no items yet is
     * never considered fully received. */
    public function isFullyReceived(): bool
    {
        return $this->items->isNotEmpty() && $this->items->every(fn (PurchaseRequestItem $i) => $i->receivedQuantity() >= $i->quantity);
    }

    public function auditLabel(): string
    {
        return "Purchase request {$this->number}";
    }

    public function auditAction(array $changed): string
    {
        return 'purchase_request.'.(in_array('status', $changed, true) ? 'status_changed' : 'updated');
    }

    public function auditReason(array $changed): ?string
    {
        return $this->status === 'rejected' ? $this->rejection_reason : null;
    }
}
