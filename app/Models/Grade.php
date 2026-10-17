<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Concerns\GuardsDeletion;
use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Grade extends Model
{
    use Audited;

    use GuardsDeletion;

    use HasFactory;

    protected $fillable = ['school_id', 'name', 'sort_order'];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function sections(): HasMany
    {
        return $this->hasMany(Section::class);
    }

    public function deletionBlockers(): array
    {
        return array_filter([
            ($n = $this->sections()->count()) ? "{$n} section(s)" : null,
            ($n = \App\Models\FeeStructure::withTrashed()->where('grade_id', $this->id)->count()) ? "{$n} fee item(s)" : null,
        ]);
    }
}
