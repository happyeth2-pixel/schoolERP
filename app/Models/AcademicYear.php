<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AcademicYear extends Model
{
    use Audited;

    use HasFactory;

    protected $fillable = ['school_id', 'name', 'start_date', 'end_date', 'is_current'];

    /**
     * One current year per school, enforced in the database: current_school_id holds the
     * school's id for the current year and NULL for every other, under a unique index.
     * It is derived from is_current on every save, so the two can never disagree.
     */
    protected static function booted(): void
    {
        static::saving(function (self $year) {
            $year->current_school_id = $year->is_current ? $year->school_id : null;
        });
    }

    /**
     * THE way to change the current year. Runs in one transaction with the school row locked,
     * un-sets the old year before setting the new one, and writes a single audit entry with the
     * reason. It only changes which year is "current"; no invoice, enrolment, mark or payment of
     * any year is touched -- historical data stays exactly as it was.
     */
    public function makeCurrent(User $actor, string $reason): void
    {
        \Illuminate\Support\Facades\DB::transaction(function () use ($actor, $reason) {
            School::whereKey($this->school_id)->lockForUpdate()->first();

            $previous = self::where('school_id', $this->school_id)->where('is_current', true)->first();

            AuditLog::suspended(function () use ($previous) {
                if ($previous && $previous->id !== $this->id) {
                    $previous->update(['is_current' => false]);
                }
                $this->update(['is_current' => true]);
            });

            AuditLog::record($actor, 'academic_year.current_changed', $this,
                ['current_year' => $previous?->name],
                ['current_year' => $this->name],
                $reason
            );
        });
    }

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'is_current' => 'boolean',
        ];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function terms(): HasMany
    {
        return $this->hasMany(Term::class);
    }

    public function sections(): HasMany
    {
        return $this->hasMany(Section::class);
    }
}
