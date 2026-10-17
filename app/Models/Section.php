<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Concerns\GuardsDeletion;
use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Section extends Model
{
    use Audited;

    use GuardsDeletion;

    use HasFactory;

    protected $fillable = ['branch_id', 'grade_id', 'academic_year_id', 'name', 'capacity', 'class_teacher_id'];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function grade(): BelongsTo
    {
        return $this->belongsTo(Grade::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function classTeacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'class_teacher_id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function teachingAssignments(): HasMany
    {
        return $this->hasMany(TeachingAssignment::class);
    }

    public function timetableSlots(): HasMany
    {
        return $this->hasMany(TimetableSlot::class);
    }

    public function deletionBlockers(): array
    {
        return array_filter([
            ($n = $this->enrollments()->count()) ? "{$n} student enrollment(s)" : null,
            ($n = \App\Models\MarkBatch::where('section_id', $this->id)->count()) ? "{$n} mark sheet(s)" : null,
            ($n = \Illuminate\Support\Facades\DB::table('report_card_snapshots')->where('section_id', $this->id)->count()) ? "{$n} report card(s)" : null,
            ($n = $this->teachingAssignments()->count()) ? "{$n} teaching assignment(s)" : null,
            ($n = $this->timetableSlots()->count()) ? "{$n} timetable slot(s)" : null,
        ]);
    }

    public function auditLabel(): string
    {
        return trim(($this->grade?->name ?? '').' '.$this->name);
    }
}
