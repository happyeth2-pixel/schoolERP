<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Exceptions\LockedRecordException;
use App\Models\Concerns\NeverHardDeleted;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Mark extends Model
{
    use NeverHardDeleted;

    use HasFactory;

    protected $fillable = ['mark_batch_id', 'enrollment_id', 'score', 'max_score'];

    /**
     * A score can be created or changed only while its batch is a draft. Once submitted, approved or
     * published it is locked -- through ANY route, not just the marks screen.
     */
    protected static function booted(): void
    {
        foreach (['creating', 'updating'] as $event) {
            static::$event(function (self $mark) {
                $status = MarkBatch::whereKey($mark->mark_batch_id)->value('status');
                if ($status !== 'draft') {
                    throw new LockedRecordException("These marks are {$status} and cannot be changed. Published results must be reopened first.");
                }
            });
        }
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(MarkBatch::class, 'mark_batch_id');
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function percentage(): ?float
    {
        if ($this->score === null || (float) $this->max_score === 0.0) {
            return null;
        }

        return round(((float) $this->score / (float) $this->max_score) * 100, 2);
    }
}
