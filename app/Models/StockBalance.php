<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The fast, lockable running total for one (item, store) pair. Never written to directly outside
 * App\Services\Stock -- see that class for why the update always happens under a row lock. */
class StockBalance extends Model
{
    protected $fillable = ['item_id', 'store_id', 'quantity'];

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'item_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(InventoryStore::class, 'store_id');
    }
}
