<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class StocktakeLine extends Model
{
    protected $fillable = ['stocktake_id', 'item_id', 'system_quantity', 'physical_quantity', 'reason'];

    protected function casts(): array
    {
        return ['system_quantity' => 'integer', 'physical_quantity' => 'integer'];
    }

    public function stocktake(): BelongsTo
    {
        return $this->belongsTo(Stocktake::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'item_id');
    }

    public function movement(): HasOne
    {
        return $this->hasOne(StockMovement::class, 'related_stocktake_line_id');
    }

    public function isCounted(): bool
    {
        return $this->physical_quantity !== null;
    }

    /** Compared against the LIVE balance at completion time, not this snapshot -- see the migration's docblock. */
    public function difference(): ?int
    {
        return $this->isCounted() ? $this->physical_quantity - $this->system_quantity : null;
    }
}
