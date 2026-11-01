<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\GuardsDeletion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A school's own bell-schedule catalog -- "Period 1" etc, each with its own default start/end clock time.
 * Academic expansion, Phase 1 (Timetable). A `TimetableSlot` always references one of these rather than
 * storing its own start/end time, so every slot in "Period 3" means the same clock time school-wide.
 */
class Period extends Model
{
    use Audited;
    use GuardsDeletion;

    public const TYPES = ['teaching', 'break', 'lunch'];

    protected $fillable = ['school_id', 'period_number', 'name', 'type', 'start_time', 'end_time'];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function timetableSlots(): HasMany
    {
        return $this->hasMany(TimetableSlot::class);
    }

    public function optionalServices(): HasMany
    {
        return $this->hasMany(OptionalService::class);
    }

    public function deletionBlockers(): array
    {
        $blockers = [];
        if (($count = $this->timetableSlots()->count()) > 0) {
            $blockers[] = "{$count} timetable slot(s)";
        }
        if (($count = $this->optionalServices()->count()) > 0) {
            $blockers[] = "{$count} after-school programme(s)";
        }

        return $blockers;
    }

    public function label(): string
    {
        return $this->name ?: "Period {$this->period_number}";
    }

    public function auditLabel(): string
    {
        return $this->label();
    }
}
