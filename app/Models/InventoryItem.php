<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A consumable's catalog entry (stationery, paper, toner, uniforms...). School-wide, like FeeStructure --
 * the same item can be stocked in several stores, each with its own StockBalance row. Never a fixed asset:
 * see App\Models\Asset for computers, furniture, vehicles and the like. */
class InventoryItem extends Model
{
    use Audited;
    use SoftDeletes;

    protected $fillable = ['school_id', 'category_id', 'name', 'sku', 'barcode', 'unit', 'description', 'min_stock_level', 'reorder_level'];

    protected function casts(): array
    {
        return [
            'min_stock_level' => 'integer',
            'reorder_level' => 'integer',
        ];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(InventoryCategory::class, 'category_id');
    }

    public function balances(): HasMany
    {
        return $this->hasMany(StockBalance::class, 'item_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'item_id');
    }

    /** On hand, across every store this list of balances covers (pass a scoped list for a branch's view). */
    public function totalOnHand(?\Illuminate\Support\Collection $balances = null): int
    {
        return ($balances ?? $this->balances)->sum('quantity');
    }

    public function isLowStock(int $onHand): bool
    {
        return $this->reorder_level > 0 && $onHand <= $this->reorder_level;
    }

    public function auditLabel(): string
    {
        return $this->name.($this->sku ? " ({$this->sku})" : '');
    }

    protected static function booted(): void
    {
        // Archiving (soft-delete) must free the item's sku/barcode for reuse by a new item -- that was
        // already the app-level validation's behaviour (`Rule::unique(...)->whereNull('deleted_at')`), but a
        // plain unique(school_id, sku) DB index can't express "ignore soft-deleted rows" portably across
        // MySQL/SQLite (no partial-index support), so instead the value is rewritten here, at the moment of
        // archiving, to something that can never collide with a live item's sku/barcode again. The original
        // value stays visible (suffixed, not erased) so an archived item's history is still readable.
        static::deleting(function (self $item) {
            if ($item->isForceDeleting()) {
                return;
            }
            foreach (['sku', 'barcode'] as $field) {
                if ($item->{$field} !== null && ! str_contains($item->{$field}, '#archived-')) {
                    $item->{$field} = substr($item->{$field}.'#archived-'.$item->id, 0, 100);
                }
            }
            if ($item->isDirty(['sku', 'barcode'])) {
                $item->saveQuietly();
            }
        });
    }
}
