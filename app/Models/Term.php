<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Concerns\GuardsDeletion;
use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Term extends Model
{
    use Audited;

    use GuardsDeletion;

    use HasFactory;

    protected $fillable = ['academic_year_id', 'name', 'start_date', 'end_date'];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function exams(): HasMany
    {
        return $this->hasMany(Exam::class);
    }

    public function deletionBlockers(): array
    {
        return array_filter([
            ($n = $this->exams()->count()) ? "{$n} exam(s)" : null,
            ($n = \App\Models\Invoice::where('term_id', $this->id)->count()) ? "{$n} invoice(s)" : null,
            ($n = \App\Models\TimetableSlot::where('term_id', $this->id)->count()) ? "{$n} timetable slot(s)" : null,
        ]);
    }
}
