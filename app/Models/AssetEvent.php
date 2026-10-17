<?php

namespace App\Models;

use App\Exceptions\DeletionBlockedException;
use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The permanent history of one asset. Never edited or deleted (see booted()) -- exactly like
 * StockMovement is to stock, this is the proof behind the asset's own status/assigned_to/current_store_id,
 * which are only ever a snapshot of where the most recent event left it. Only ever created by
 * App\Services\Assets. */
class AssetEvent extends Model
{
    use Audited;

    public const TYPES = [
        'assigned', 'reassigned', 'transferred', 'repair_started', 'repair_completed',
        'returned', 'retired', 'disposed', 'marked_lost', 'marked_damaged',
    ];

    protected $fillable = [
        'asset_id', 'event_type', 'from_user_id', 'to_user_id', 'from_store_id', 'to_store_id', 'notes', 'performed_by',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new DeletionBlockedException('Asset events cannot be changed once recorded -- record a correcting event instead.');
        });

        static::deleting(function () {
            throw new DeletionBlockedException('Asset events cannot be deleted -- they are the asset\'s history.');
        });
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function fromUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    public function toUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }

    public function fromStore(): BelongsTo
    {
        return $this->belongsTo(InventoryStore::class, 'from_store_id');
    }

    public function toStore(): BelongsTo
    {
        return $this->belongsTo(InventoryStore::class, 'to_store_id');
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    public function auditLabel(): string
    {
        return "{$this->event_type} on {$this->asset?->asset_tag}";
    }
}
