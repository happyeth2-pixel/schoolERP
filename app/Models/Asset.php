<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A durable, individually-tracked piece of school property -- a computer, a desk, a vehicle. Never an
 * App\Models\InventoryItem: a consumable is counted, an asset is the one specific thing with its own tag
 * and its own history (see AssetEvent) of who had it, where it sat, and what happened to it. */
class Asset extends Model
{
    use Audited;
    use SoftDeletes;

    public const CONDITIONS = ['good', 'fair', 'poor'];

    public const STATUSES = ['available', 'assigned', 'under_repair', 'lost', 'damaged', 'retired', 'disposed'];

    protected $fillable = [
        'school_id', 'branch_id', 'category_id', 'asset_tag', 'name', 'serial_number', 'barcode',
        'purchase_date', 'acquisition_cost', 'supplier', 'supplier_id', 'current_store_id', 'assigned_to',
        'condition', 'status', 'warranty_expiry', 'notes', 'attachments',
    ];

    protected function casts(): array
    {
        return [
            'purchase_date' => 'date',
            'acquisition_cost' => 'decimal:2',
            'warranty_expiry' => 'date',
            'attachments' => 'array',
        ];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(InventoryCategory::class, 'category_id');
    }

    public function currentStore(): BelongsTo
    {
        return $this->belongsTo(InventoryStore::class, 'current_store_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** Optional link to a real Supplier record (Procurement). `supplier` (free text) is the frozen,
     * always-reliable display value snapshotted at creation time; this relation is only for a live link-through
     * to that supplier's own record, and is never itself the source of truth for "who sold us this." */
    public function supplierRecord(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(AssetEvent::class);
    }

    public function auditLabel(): string
    {
        return "{$this->asset_tag} {$this->name}";
    }

    public function auditAction(array $changed): string
    {
        return 'asset.'.(in_array('status', $changed, true) ? 'status_changed' : 'updated');
    }
}
