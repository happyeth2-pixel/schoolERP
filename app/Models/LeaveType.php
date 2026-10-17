<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\GuardsDeletion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A school's own configurable leave categories (Annual, Sick, Maternity, Unpaid...) -- never a hardcoded
 * list. `default_annual_days` is only a suggestion offered when HR creates a new `LeaveAllocation`; it is
 * never applied automatically. `requires_document` makes a supporting document mandatory when SUBMITTING a
 * request of this type (a draft can still be started without one). */
class LeaveType extends Model
{
    use Audited;
    use GuardsDeletion;

    protected $fillable = ['school_id', 'name', 'default_annual_days', 'requires_document', 'notes'];

    protected function casts(): array
    {
        return ['requires_document' => 'boolean', 'default_annual_days' => 'decimal:1'];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(LeaveAllocation::class);
    }

    public function requests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function deletionBlockers(): array
    {
        $blockers = [];
        if (($count = $this->allocations()->count()) > 0) {
            $blockers[] = "{$count} allocation(s)";
        }
        if (($count = $this->requests()->count()) > 0) {
            $blockers[] = "{$count} leave request(s)";
        }

        return $blockers;
    }

    public function auditLabel(): string
    {
        return "Leave type {$this->name}";
    }
}
