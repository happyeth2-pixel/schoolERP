<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\GuardsDeletion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A branch's own physical room -- academic expansion, Phase 1 (Timetable). Unlike Period (a bell-schedule
 * concept shared schoolwide), a room is a physical location belonging to exactly one branch. */
class Room extends Model
{
    use Audited;
    use GuardsDeletion;

    protected $fillable = ['branch_id', 'name', 'capacity'];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function timetableSlots(): HasMany
    {
        return $this->hasMany(TimetableSlot::class);
    }

    public function deletionBlockers(): array
    {
        $blockers = [];
        if (($count = $this->timetableSlots()->count()) > 0) {
            $blockers[] = "{$count} timetable slot(s)";
        }

        return $blockers;
    }

    public function auditLabel(): string
    {
        return $this->name;
    }
}
