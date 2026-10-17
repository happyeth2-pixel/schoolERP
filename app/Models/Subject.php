<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Concerns\GuardsDeletion;
use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subject extends Model
{
    use Audited;

    use GuardsDeletion;

    use HasFactory;

    protected $fillable = ['school_id', 'name', 'code'];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function deletionBlockers(): array
    {
        return array_filter([
            ($n = \App\Models\MarkBatch::where('subject_id', $this->id)->count()) ? "{$n} mark sheet(s)" : null,
            ($n = \App\Models\TeachingAssignment::where('subject_id', $this->id)->count()) ? "{$n} teaching assignment(s)" : null,
            ($n = \App\Models\TimetableSlot::where('subject_id', $this->id)->count()) ? "{$n} timetable slot(s)" : null,
        ]);
    }
}
