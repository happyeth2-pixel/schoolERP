<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\GuardsDeletion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    use Audited;
    use GuardsDeletion;

    protected $fillable = ['school_id', 'branch_id', 'name', 'manager_employee_id', 'notes'];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_employee_id');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function positions(): HasMany
    {
        return $this->hasMany(Position::class);
    }

    public function deletionBlockers(): array
    {
        $blockers = [];
        if (($count = $this->employees()->count()) > 0) {
            $blockers[] = "{$count} employee(s)";
        }
        if (($count = $this->positions()->count()) > 0) {
            $blockers[] = "{$count} position(s)";
        }

        return $blockers;
    }

    public function auditLabel(): string
    {
        return "Department {$this->name}";
    }
}
