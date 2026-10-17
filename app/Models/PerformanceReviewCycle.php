<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\GuardsDeletion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A school's own named review period (e.g. "Mid-Year 2026", "Annual 2026") -- HR Module Phase E. A cycle is
 * just the grouping/labelling for a batch of `PerformanceReview` rows, one per employee reviewed in it.
 */
class PerformanceReviewCycle extends Model
{
    use Audited;
    use GuardsDeletion;

    protected $fillable = ['school_id', 'name', 'start_date', 'end_date', 'notes'];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(PerformanceReview::class, 'cycle_id');
    }

    public function deletionBlockers(): array
    {
        $blockers = [];
        if (($count = $this->reviews()->count()) > 0) {
            $blockers[] = "{$count} performance review(s)";
        }

        return $blockers;
    }

    public function auditLabel(): string
    {
        return $this->name;
    }
}
