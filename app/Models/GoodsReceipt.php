<?php

namespace App\Models;

use App\Exceptions\DeletionBlockedException;
use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A physical delivery against a purchase order -- once recorded it has already moved real stock (via
 * App\Services\Stock, the same service every other inventory phase uses), so like StockMovement/AssetEvent
 * it is never edited or removed. A mistake is corrected with a further stock movement (a return), never by
 * rewriting this record.
 */
class GoodsReceipt extends Model
{
    use Audited;

    protected $fillable = ['school_id', 'branch_id', 'purchase_order_id', 'store_id', 'number', 'received_date', 'notes', 'recorded_by'];

    protected function casts(): array
    {
        return ['received_date' => 'date'];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new DeletionBlockedException('A goods receipt cannot be changed once recorded -- it has already moved stock. Record a correcting movement instead.');
        });

        static::deleting(function () {
            throw new DeletionBlockedException('A goods receipt cannot be removed -- it is part of the stock history.');
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

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(InventoryStore::class, 'store_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(GoodsReceiptItem::class);
    }

    public function auditLabel(): string
    {
        return "Goods receipt {$this->number}";
    }
}
