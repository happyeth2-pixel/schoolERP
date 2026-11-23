<?php

namespace App\Models;

use App\Models\Concerns\NeverHardDeleted;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A principal/owner's editorial pick for the public homepage's "top students" block. See the migration
 *  for why this is a deliberate record, not a live query. */
class TopStudentHighlight extends Model
{
    use NeverHardDeleted;

    protected $fillable = ['school_id', 'student_id', 'section_id', 'academic_year_id', 'rank', 'overall_average', 'posted_by', 'published_at'];

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }
}
