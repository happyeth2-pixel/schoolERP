<?php

namespace App\Models;

use App\Exceptions\DeletionBlockedException;
use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The permanent record of one stock change. Never edited or deleted (see booted() below) -- a mistake is
 * corrected with another movement (an adjustment, a return), never by rewriting history. Only ever created
 * by App\Services\Stock, which keeps stock_balances in step with it inside the same transaction.
 *
 * A transfer is two rows -- transfer_out at the source store, transfer_in at the destination -- linked by
 * related_movement_id, so every row here still means exactly one store and one signed effect on it.
 */
class StockMovement extends Model
{
    use Audited;

    public const INCREASE_TYPES = ['opening', 'purchase_receipt', 'return', 'transfer_in', 'adjustment_increase'];

    public const DECREASE_TYPES = ['issue', 'transfer_out', 'damaged', 'lost', 'adjustment_decrease'];

    public const TYPES = [...self::INCREASE_TYPES, ...self::DECREASE_TYPES];

    protected $fillable = [
        'school_id', 'item_id', 'store_id', 'type', 'quantity', 'reference_number', 'reason',
        'related_movement_id', 'related_requisition_item_id', 'related_stocktake_line_id',
        'related_goods_receipt_item_id', 'performed_by',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new DeletionBlockedException('Stock movements cannot be changed once recorded -- record a correcting movement instead.');
        });

        static::deleting(function () {
            throw new DeletionBlockedException('Stock movements cannot be deleted -- they are the stock history.');
        });
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'item_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(InventoryStore::class, 'store_id');
    }

    public function relatedMovement(): BelongsTo
    {
        return $this->belongsTo(self::class, 'related_movement_id');
    }

    public function requisitionItem(): BelongsTo
    {
        return $this->belongsTo(RequisitionItem::class, 'related_requisition_item_id');
    }

    public function stocktakeLine(): BelongsTo
    {
        return $this->belongsTo(StocktakeLine::class, 'related_stocktake_line_id');
    }

    public function goodsReceiptItem(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiptItem::class, 'related_goods_receipt_item_id');
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    public function isIncrease(): bool
    {
        return in_array($this->type, self::INCREASE_TYPES, true);
    }

    public function auditLabel(): string
    {
        $sign = $this->isIncrease() ? '+' : '-';

        return "{$this->type}: {$sign}{$this->quantity} {$this->item?->name} @ {$this->store?->name}";
    }
}
