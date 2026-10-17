<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\GuardsDeletion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A library catalog title -- school-wide (the same split `Period` already uses for a shared bell schedule),
 * never per-branch. Academic expansion, Phase 6. The actual trackable units are its `copies`.
 */
class Book extends Model
{
    use Audited;
    use GuardsDeletion;

    protected $fillable = ['school_id', 'title', 'author', 'isbn', 'category', 'created_by'];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function copies(): HasMany
    {
        return $this->hasMany(BookCopy::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function deletionBlockers(): array
    {
        $blockers = [];
        if (($count = $this->copies()->count()) > 0) {
            $blockers[] = "{$count} copy/copies";
        }

        return $blockers;
    }

    public function auditLabel(): string
    {
        return $this->title;
    }
}
