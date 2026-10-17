<?php

namespace App\Models;

use App\Exceptions\DeletionBlockedException;
use App\Models\Concerns\NeverHardDeleted;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One published VERSION of a mark batch: who published it, when, and the exact scores that were
 * published. Only the "reopened" fields can ever be filled in afterwards (once, when it is reopened).
 */
class MarkBatchPublication extends Model
{
    use NeverHardDeleted;

    protected $fillable = ['mark_batch_id', 'version', 'published_by', 'published_at', 'marks_snapshot', 'reopened_by', 'reopened_at', 'reopen_reason'];

    protected function casts(): array
    {
        return ['published_at' => 'datetime', 'reopened_at' => 'datetime', 'marks_snapshot' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $p) {
            $allowed = ['reopened_by', 'reopened_at', 'reopen_reason', 'updated_at'];
            if ($p->getOriginal('reopened_at') !== null || array_diff(array_keys($p->getDirty()), $allowed)) {
                throw new DeletionBlockedException('A published version can only be closed once (by reopening); it cannot otherwise be changed.');
            }
        });
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(MarkBatch::class, 'mark_batch_id');
    }

    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function reopenedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }
}
