<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A supplier's quoted response to an APPROVED purchase request. `selected` marks the one a human chose --
 * never set automatically, per the brief. Totals are never cached: always summed live from the items,
 * same rule every other total in this app follows. */
class Quotation extends Model
{
    use Audited;

    protected $fillable = ['school_id', 'purchase_request_id', 'supplier_id', 'reference', 'quotation_date', 'validity_date', 'delivery_info', 'notes', 'selected', 'recorded_by'];

    protected function casts(): array
    {
        return ['quotation_date' => 'date', 'validity_date' => 'date', 'selected' => 'boolean'];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class);
    }

    public function total(): float
    {
        return (float) $this->items->sum('total');
    }

    public function auditLabel(): string
    {
        return "Quotation from {$this->supplier?->name} for {$this->purchaseRequest?->number}";
    }

    public function auditAction(array $changed): string
    {
        if (in_array('selected', $changed, true)) {
            return 'quotation.'.($this->selected ? 'selected' : 'deselected');
        }

        return 'quotation.updated';
    }
}
