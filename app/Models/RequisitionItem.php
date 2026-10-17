<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RequisitionItem extends Model
{
    protected $fillable = ['requisition_id', 'item_id', 'quantity_requested', 'quantity_approved', 'quantity_issued', 'notes'];

    protected function casts(): array
    {
        return [
            'quantity_requested' => 'integer',
            'quantity_approved' => 'integer',
            'quantity_issued' => 'integer',
        ];
    }

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(Requisition::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'item_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'related_requisition_item_id');
    }

    public function remaining(): int
    {
        return max(0, (int) $this->quantity_approved - $this->quantity_issued);
    }
}
