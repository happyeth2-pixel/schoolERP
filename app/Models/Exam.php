<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Concerns\GuardsDeletion;
use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Exam extends Model
{
    use Audited;

    use GuardsDeletion;

    use HasFactory;

    protected $fillable = ['school_id', 'term_id', 'name', 'type', 'weight_percent'];

    /**
     * THE RULE: the exams of one term (the grading period) must add up to
     * exactly 100%. Weights are the same for every subject, so this is
     * checked per term. While a term is still being set up the total may be
     * below 100, never above; results cannot be PUBLISHED until it is exactly
     * 100, and once anything in the term is published its weights are locked.
     */
    public const WEIGHT_TOTAL = 100.0;

    public static function termWeightTotal(int $termId, ?int $exceptExamId = null): float
    {
        return round((float) static::where('term_id', $termId)
            ->when($exceptExamId, fn ($q) => $q->where('id', '!=', $exceptExamId))
            ->sum('weight_percent'), 2);
    }

    public static function isCompleteTotal(float $total): bool
    {
        return abs($total - self::WEIGHT_TOTAL) < 0.005;
    }

    public static function termHasPublishedResults(int $termId): bool
    {
        return MarkBatch::where('status', 'published')
            ->whereHas('exam', fn ($q) => $q->where('term_id', $termId))
            ->exists();
    }

    /** Term ids (of the given school) whose exam weights do NOT total exactly 100. */
    public static function incompleteTermIds(int $schoolId): array
    {
        return static::where('school_id', $schoolId)
            ->selectRaw('term_id, SUM(weight_percent) as total')
            ->groupBy('term_id')
            ->get()
            ->reject(fn ($row) => static::isCompleteTotal((float) $row->total))
            ->pluck('term_id')
            ->all();
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    public function markBatches(): HasMany
    {
        return $this->hasMany(MarkBatch::class);
    }

    public function deletionBlockers(): array
    {
        return array_filter([
            ($n = $this->markBatches()->count()) ? "{$n} mark sheet(s)" : null,
        ]);
    }
}
