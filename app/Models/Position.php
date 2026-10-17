<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\GuardsDeletion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** The brief's "Job Title" -- deliberately a real entity, never to be confused with a Spatie system role
 * (see User::assignTier()). "Senior Accountant" (Position/Job Title) vs "Accountant" (system role) are two
 * different concepts answering two different questions: what this person is called at the school vs what
 * they are allowed to do in the system. */
class Position extends Model
{
    use Audited;
    use GuardsDeletion;

    protected $fillable = ['school_id', 'department_id', 'title', 'description'];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function deletionBlockers(): array
    {
        $blockers = [];
        if (($count = $this->employees()->count()) > 0) {
            $blockers[] = "{$count} employee(s)";
        }

        return $blockers;
    }

    public function auditLabel(): string
    {
        return "Position {$this->title}";
    }
}
