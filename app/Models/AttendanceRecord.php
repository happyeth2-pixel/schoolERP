<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Concerns\Audited;
use App\Models\Concerns\NeverHardDeleted;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceRecord extends Model
{
    use Audited;
    use NeverHardDeleted;

    use HasFactory;

    protected $fillable = ['enrollment_id', 'date', 'status', 'marked_by', 'remarks'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function markedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marked_by');
    }

    /** Taking the register writes one row per child per day; only CHANGES to a mark are worth an audit entry. */
    public function auditsCreation(): bool
    {
        return false;
    }

    public function auditAction(array $changed): string
    {
        return 'attendance.status_changed';
    }

    public function auditLabel(): string
    {
        return trim(($this->enrollment?->student?->fullName() ?? 'Enrollment #'.$this->enrollment_id).' on '.($this->date?->toDateString() ?? ''));
    }
}
