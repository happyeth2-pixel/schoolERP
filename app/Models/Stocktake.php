<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A physical count of one store's stock against the system. "Draft" from the brief is skipped -- starting
 * a count IS being in progress. Completing it turns every line's difference into a real stock_movements
 * adjustment via App\Services\Stock::adjust(), never a silent overwrite of stock_balances. */
class Stocktake extends Model
{
    use Audited;

    public const STATUSES = ['in_progress', 'completed'];

    protected $fillable = ['school_id', 'branch_id', 'store_id', 'status', 'started_by', 'started_at', 'completed_by', 'completed_at', 'notes'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'completed_at' => 'datetime'];
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

    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StocktakeLine::class);
    }

    public function auditLabel(): string
    {
        return 'Stocktake #'.$this->id.' -- '.($this->store?->name ?? '');
    }

    public function auditAction(array $changed): string
    {
        return 'stocktake.'.(in_array('status', $changed, true) ? 'status_changed' : 'updated');
    }
}
