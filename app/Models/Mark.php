<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Mark extends Model
{
    use HasFactory;

    protected $fillable = ['mark_batch_id', 'enrollment_id', 'score', 'max_score'];

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
