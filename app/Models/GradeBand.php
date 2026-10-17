<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GradeBand extends Model
{
    use Audited;

    use SoftDeletes;

    use HasFactory;

    protected $fillable = ['school_id', 'min_score', 'max_score', 'letter'];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public static function letterFor(int $schoolId, float $percentage): ?string
    {
        return static::where('school_id', $schoolId)
            ->where('min_score', '<=', $percentage)
            ->where('max_score', '>=', $percentage)
            ->value('letter');
    }

    public function auditLabel(): string
    {
        return 'Grade band '.$this->letter;
    }
}
